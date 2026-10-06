<?php

/**
 * -------------------------------------------------------------------------
 * PNCP plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of PNCP plugin for GLPI.
 *
 * PNCP plugin for GLPI is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PNCP plugin for GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PNCP plugin for GLPI. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Pncp\Sync;

use DateInterval;
use DateTimeImmutable;
use GlpiPlugin\Pncp\Api\Pncp;
use GlpiPlugin\Pncp\Api\PncpException;
use GlpiPlugin\Pncp\Config;
use GlpiPlugin\Pncp\Exporter;
use GlpiPlugin\Pncp\Store;
use GlpiPlugin\Pncp\Util;
use GlpiPlugin\Pncp\Watch;
use Throwable;

/**
 * Incremental, resumable synchronization of one monitored scope (Watch).
 *
 * Window phases (procurements, contracts, price records, PCA) walk date windows
 * from a per-phase cursor; the cursor only moves past a window once every query
 * of that window succeeded (a failure is never "no data"), and it is saved after
 * each window so a run cut by the time budget resumes where it stopped.
 * Detail phases (items/results, amendments) revisit only records whose portal
 * update date changed since they were last fetched.
 */
final class Syncer
{
    public const STATUS_OK      = 'ok';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ERROR   = 'error';
    /** Another run holds the lock of this scope; nothing was done. */
    public const STATUS_BUSY    = 'busy';

    private const UNITS_REFRESH_DAYS = 30;

    private Watch $watch;
    private Store $store;
    private array $cursors = [];
    private ?string $unitFilter = null;
    /** @var array<int, ?string> unit spellings to query ([null] = the whole CNPJ) */
    private array $unitSpellings = [null];
    private int $records = 0;
    private array $errors = [];

    public function __construct(
        private Pncp $api,
    ) {}

    /** @return array{status: string, records: int, message: string} */
    public function run(Watch $watch): array
    {
        $this->watch   = $watch;
        $this->store   = Store::forWatch($watch);
        $this->cursors = json_decode((string) ($watch->fields['cursors'] ?? ''), true) ?: [];
        $this->records = 0;
        $this->errors  = [];
        $cnpj = (string) $watch->fields['cnpj'];
        $this->unitFilter = Util::unitCode($watch->fields['unit_code'] ?? '') ?: null;

        $status = self::STATUS_OK;
        try {
            $this->refreshUnits($cnpj);
            $this->unitSpellings = $this->unitFilter === null ? [null] : $this->unitSpellings($cnpj, $this->unitFilter);

            $phases = [
                'procurements' => ['sync_procurements', fn($a, $b) => $this->procurementsWindow($cnpj, $a, $b), Config::get('window_procurements')],
                'contracts'    => ['sync_contracts', fn($a, $b) => $this->contractsWindow($cnpj, $a, $b), Config::get('window_others')],
                'pricerecords' => ['sync_pricerecords', fn($a, $b) => $this->priceRecordsWindow($cnpj, $a, $b), Config::get('window_others')],
                'pca'          => ['sync_pca', fn($a, $b) => $this->pcaWindow($cnpj, $a, $b), Config::get('window_others')],
            ];
            foreach ($phases as $phase => [$flag, $fetch, $days]) {
                if (!empty($watch->fields[$flag])) {
                    $this->windowPhase($phase, $fetch, (int) $days);
                }
            }
            if (!empty($watch->fields['sync_items'])) {
                $this->itemsPhase();
            }
            if (!empty($watch->fields['sync_amendments'])) {
                $this->amendmentsPhase();
            }
            if (!empty($watch->fields['sync_commitments'])) {
                $this->commitmentsPhase();
            }
            if (!empty($watch->fields['auto_suppliers']) || !empty($watch->fields['auto_contracts'])) {
                $this->exportPhase();
            }
        } catch (PncpException $e) {
            if (!$e->budgetExhausted) {
                $this->errors[] = $e->getMessage();
            }
            $status = $e->budgetExhausted ? self::STATUS_PARTIAL : self::STATUS_ERROR;
        } catch (Throwable $e) {
            $this->errors[] = get_class($e) . ': ' . $e->getMessage();
            $status = self::STATUS_ERROR;
        }
        if ($status === self::STATUS_OK && $this->errors !== []) {
            $status = self::STATUS_PARTIAL;
        }

        $message = $status === self::STATUS_PARTIAL && $this->errors === []
            ? 'Time budget reached; synchronization continues on the next run'
            : implode("\n", array_slice($this->errors, 0, 5));
        // Direct write: run status is bookkeeping, not a user change (no history entry every run).
        $state = [
            'cursors'      => json_encode($this->cursors),
            'last_sync'    => date('Y-m-d H:i:s'),
            'last_status'  => $status,
            'last_message' => $message,
        ];
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Watch::getTable(), $state, ['id' => $watch->getID()]);
        $watch->fields = $state + $watch->fields;

        return ['status' => $status, 'records' => $this->records, 'message' => $message];
    }

    // ── window phases ─────────────────────────────────────────────────────

    /**
     * Walk the windows of one phase from its cursor up to today.
     *
     * @param callable(DateTimeImmutable, DateTimeImmutable): int $fetch
     */
    private function windowPhase(string $phase, callable $fetch, int $days): void
    {
        $today = new DateTimeImmutable('today');
        $cursor = $this->cursors[$phase] ?? null;
        $from = $cursor !== null
            // one day of overlap: records updated on the cursor day itself are fetched again (idempotent)
            ? (new DateTimeImmutable($cursor))->sub(new DateInterval('P1D'))
            : $this->beginDate();
        if ($from > $today) {
            return;
        }

        foreach (Util::windows($from, $today, max(1, min(364, $days))) as [$a, $b]) {
            $requests = $this->api->client->requests;
            try {
                $n = $fetch($a, $b);
            } catch (PncpException $e) {
                $this->log($phase, $a, $b, 0, $requests, $e->budgetExhausted ? self::STATUS_PARTIAL : self::STATUS_ERROR, $e->getMessage());
                throw $e;
            }
            $this->records += $n;
            $this->cursors[$phase] = $b->format('Y-m-d');
            $this->saveCursors();
            $this->log($phase, $a, $b, $n, $requests, self::STATUS_OK);
        }
    }

    private function beginDate(): DateTimeImmutable
    {
        $begin = $this->watch->fields['date_begin'] ?? null;

        return new DateTimeImmutable($begin ?: date('Y') . '-01-01');
    }

    private function procurementsWindow(string $cnpj, DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        $n = 0;
        foreach ($this->unitSpellings as $spelling) {
            foreach (array_keys(Pncp::MODALITIES) as $modality) {
                foreach ($this->api->procurementsUpdated($cnpj, $spelling, $a, $b, $modality) as $raw) {
                    if ($this->outOfScope(Util::unitCode($raw['unidadeOrgao']['codigoUnidade'] ?? ''))) {
                        continue;
                    }
                    $n += $this->store->procurement($raw) ? 1 : 0;
                }
            }
        }

        return $n;
    }

    private function contractsWindow(string $cnpj, DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        $n = 0;
        foreach ($this->api->contractsUpdated($cnpj, $a, $b) as $raw) {
            if (!$this->outOfScope(Util::unitCode($raw['unidadeOrgao']['codigoUnidade'] ?? ''))) {
                $n += $this->store->contract($raw) ? 1 : 0;
            }
        }

        return $n;
    }

    private function priceRecordsWindow(string $cnpj, DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        $n = 0;
        foreach ($this->api->priceRecordsUpdated($cnpj, $a, $b) as $raw) {
            if (!$this->outOfScope(Util::unitCode($raw['codigoUnidadeOrgao'] ?? ''))) {
                $n += $this->store->priceRecord($raw) ? 1 : 0;
            }
        }

        return $n;
    }

    private function pcaWindow(string $cnpj, DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        $n = 0;
        foreach ($this->api->pcaUpdated($cnpj, $a, $b) as $plan) {
            if (!$this->outOfScope(Util::unitCode($plan['codigoUnidade'] ?? ''))) {
                $n += $this->store->pcaPlan($plan);
            }
        }

        return $n;
    }

    /** Records of another unit published under the same CNPJ are left out when the watch targets one unit. */
    private function outOfScope(string $unitCode): bool
    {
        return $this->unitFilter !== null && $unitCode !== '' && $unitCode !== $this->unitFilter;
    }

    // ── detail phases ─────────────────────────────────────────────────────

    /** Items and results of new or changed procurements, newest first. */
    private function itemsPhase(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $requests = $this->api->client->requests;
        $done = 0;
        $unavailable = 0;
        $pending = $DB->request([
            'SELECT' => ['id', 'cnpj', 'year', 'sequential', 'date_update'],
            'FROM'   => 'glpi_plugin_pncp_procurements',
            'WHERE'  => [
                'plugin_pncp_watches_id' => $this->watch->getID(),
                'sequential'             => ['>', 0],
                'OR'                     => [
                    'items_version' => null,
                    new \Glpi\DBAL\QueryExpression('`items_version` <> `date_update`'),
                ],
            ],
            'ORDER'  => 'date_update DESC',
        ]);
        foreach ($pending as $p) {
            try {
                $done += $this->fetchItems((int) $p['id'], $p['cnpj'], (int) $p['year'], (int) $p['sequential'], $p['date_update']);
            } catch (PncpException $e) {
                if (!$e->unavailable) {
                    $this->log('items', null, null, $done, $requests, $e->budgetExhausted ? self::STATUS_PARTIAL : self::STATUS_ERROR, $e->getMessage());
                    throw $e;
                }
                $unavailable++; // stays pending for the next run: unavailable is not "no items"
            }
        }
        $this->records += $done;
        $this->log(
            'items',
            null,
            null,
            $done,
            $requests,
            self::STATUS_OK,
            $unavailable ? "$unavailable procurement(s) without item listing (portal 404); kept pending" : null,
        );
    }

    /** Fetch items of one procurement and the results of the items that changed. */
    public function fetchItems(int $procurementsId, string $cnpj, int $year, int $seq, ?string $version): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $known = [];
        foreach ($DB->request([
            'SELECT' => ['item_number', 'date_update', 'awarded_unit_value', 'raw'],
            'FROM'   => 'glpi_plugin_pncp_procurementitems',
            'WHERE'  => ['plugin_pncp_procurements_id' => $procurementsId],
        ]) as $row) {
            $known[(int) $row['item_number']] = $row;
        }

        $pairs = [];
        foreach ($this->api->procurementItems($cnpj, $year, $seq) as $item) {
            $number = (int) ($item['numeroItem'] ?? 0);
            $old = $known[$number] ?? null;
            $changed = $old === null || $old['date_update'] !== Util::datetime($item['dataAtualizacao'] ?? null);
            $result = null;
            if (!empty($item['temResultado'])) {
                if ($changed || $old['awarded_unit_value'] === null) {
                    $result = $this->api->itemResult($cnpj, $year, $seq, $number);
                } else {
                    $result = json_decode((string) $old['raw'], true)['resultado'] ?? null;
                }
            }
            $pairs[] = [$item, $result];
        }

        // Items are children of the procurement: no ownership columns, any Store will do
        // (this method is also called outside run(), by manual imports).
        return (new Store())->procurementItems($procurementsId, $pairs, $version);
    }

    /** Amendments of new or changed contracts. */
    private function amendmentsPhase(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $requests = $this->api->client->requests;
        $done = 0;
        $pending = $DB->request([
            'SELECT' => ['id', 'cnpj', 'year', 'sequential', 'date_update'],
            'FROM'   => 'glpi_plugin_pncp_contracts',
            'WHERE'  => [
                'plugin_pncp_watches_id' => $this->watch->getID(),
                'sequential'             => ['>', 0],
                'OR'                     => [
                    'amendments_version' => null,
                    new \Glpi\DBAL\QueryExpression('`amendments_version` <> `date_update`'),
                ],
            ],
            'ORDER'  => 'date_update DESC',
        ]);
        foreach ($pending as $c) {
            try {
                $list = $this->api->contractAmendments($c['cnpj'], (int) $c['year'], (int) $c['sequential']);
                $done += $this->store->amendments((int) $c['id'], $list, $c['date_update']);
            } catch (PncpException $e) {
                if (!$e->unavailable) {
                    $this->log('amendments', null, null, $done, $requests, $e->budgetExhausted ? self::STATUS_PARTIAL : self::STATUS_ERROR, $e->getMessage());
                    throw $e;
                }
            }
        }
        $this->records += $done;
        $this->log('amendments', null, null, $done, $requests, self::STATUS_OK);
    }

    /** Commitment notes (empenhos) of new or changed contracts. */
    private function commitmentsPhase(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $requests = $this->api->client->requests;
        $done = 0;
        $pending = $DB->request([
            'SELECT' => ['id', 'cnpj', 'year', 'sequential', 'date_update'],
            'FROM'   => 'glpi_plugin_pncp_contracts',
            'WHERE'  => [
                'plugin_pncp_watches_id' => $this->watch->getID(),
                'sequential'             => ['>', 0],
                'OR'                     => [
                    'commitments_version' => null,
                    new \Glpi\DBAL\QueryExpression('`commitments_version` <> `date_update`'),
                ],
            ],
            'ORDER'  => 'date_update DESC',
        ]);
        foreach ($pending as $c) {
            try {
                $list = $this->api->contractCommitments($c['cnpj'], (int) $c['year'], (int) $c['sequential']);
                $done += $this->store->commitments((int) $c['id'], $list, $c['date_update']);
            } catch (PncpException $e) {
                if (!$e->unavailable) {
                    $this->log('commitments', null, null, $done, $requests, $e->budgetExhausted ? self::STATUS_PARTIAL : self::STATUS_ERROR, $e->getMessage());
                    throw $e;
                }
            }
        }
        $this->records += $done;
        $this->log('commitments', null, null, $done, $requests, self::STATUS_OK);
    }

    /** Copy new or changed contracts (and their suppliers) to GLPI core objects. */
    private function exportPhase(): void
    {
        $exporter = new Exporter();
        $n = $exporter->exportPending(
            $this->watch->getID(),
            !empty($this->watch->fields['auto_contracts']),
            !empty($this->watch->fields['auto_suppliers']),
        );
        $this->log('export', null, null, $n, $this->api->client->requests, self::STATUS_OK, $exporter->lastErrors ? implode("\n", $exporter->lastErrors) : null);
    }

    // ── units ─────────────────────────────────────────────────────────────

    private function refreshUnits(string $cnpj): void
    {
        $last = $this->cursors['units'] ?? null;
        if ($last !== null && strtotime($last) > strtotime('-' . self::UNITS_REFRESH_DAYS . ' days')) {
            return;
        }
        try {
            $count = Store::units($cnpj, $this->api->units($cnpj));
            if ($count > 0) {
                $this->cursors['units'] = date('Y-m-d');
            }
        } catch (PncpException $e) {
            if ($e->budgetExhausted) {
                throw $e;
            }
            // The catalogue is a convenience: the synchronization goes on without it
            // (logged, but it does not mark the run as partial; retried next run).
            $this->log('units', null, null, 0, $this->api->client->requests, self::STATUS_ERROR, $e->getMessage());
        }
    }

    /**
     * Spellings of a unit code as registered on the portal ("012345", "12345"...).
     * codigoUnidadeAdministrativa matches the spelling exactly, and records may be
     * published under any of them, so each one is queried (an empty answer is cheap).
     *
     * @return string[]
     */
    private function unitSpellings(string $cnpj, string $normalized): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $spellings = array_column(iterator_to_array($DB->request([
            'SELECT' => 'pncp_code',
            'FROM'   => 'glpi_plugin_pncp_units',
            'WHERE'  => ['cnpj' => $cnpj, 'code' => $normalized],
        ]), false), 'pncp_code');

        return $spellings ?: [trim((string) $this->watch->fields['unit_code'])];
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function saveCursors(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update('glpi_plugin_pncp_watches', ['cursors' => json_encode($this->cursors)], ['id' => $this->watch->getID()]);
    }

    private function log(string $phase, ?DateTimeImmutable $a, ?DateTimeImmutable $b, int $records, int $requestsBefore, string $status, ?string $message = null): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->insert('glpi_plugin_pncp_synclogs', [
            'plugin_pncp_watches_id' => $this->watch->getID(),
            'date'                   => date('Y-m-d H:i:s'),
            'phase'                  => $phase,
            'window_begin'           => $a?->format('Y-m-d'),
            'window_end'             => $b?->format('Y-m-d'),
            'records'                => $records,
            'requests'               => $this->api->client->requests - $requestsBefore,
            'status'                 => $status,
            'message'                => $message,
        ]);
    }
}
