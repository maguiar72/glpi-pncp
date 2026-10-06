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

namespace GlpiPlugin\Pncp;

use Contract as GlpiContract;
use Contract_Supplier;
use ContractCost;
use ContractType;
use DateTimeImmutable;
use Dropdown;
use Supplier;
use Throwable;

/**
 * Copies PNCP records into GLPI core objects (Suppliers, Contracts).
 *
 * Uses the regular CommonDBTM API on purpose: core objects get history,
 * notifications and rules like any manual edit. Only the fields that come
 * from the portal are written on update; anything else edited in GLPI stays.
 */
final class Exporter
{
    /** @var string[] */
    public array $lastErrors = [];

    /** @var int[] GLPI contracts that already existed and were linked (not created) in this run */
    public array $adopted = [];

    /** Find or create the GLPI supplier of a CNPJ/CPF; returns its id (0 when there is nothing to link). */
    public function supplier(?string $ni, ?string $name, int $entitiesId): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ni = Util::ni($ni);
        if ($ni === '') {
            return 0;
        }

        $link = $DB->request(['FROM' => 'glpi_plugin_pncp_supplierlinks', 'WHERE' => ['ni' => $ni]])->current();
        $supplier = new Supplier();
        if ($link !== null && $supplier->getFromDB((int) $link['suppliers_id']) && !$supplier->fields['is_deleted']) {
            return $supplier->getID();
        }

        // A supplier registered by hand with the same CNPJ/CPF (formatted or not) is reused.
        $existing = $DB->request([
            'SELECT' => 'id',
            'FROM'   => Supplier::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'registration_number' => array_unique([$ni, Util::formatNi($ni)])],
            'LIMIT'  => 1,
        ])->current();
        $id = (int) ($existing['id'] ?? 0);

        if ($id === 0) {
            $id = (int) $supplier->add([
                'name'                => $name ?: Util::formatNi($ni),
                'registration_number' => Util::formatNi($ni),
                'entities_id'         => $entitiesId,
                'is_recursive'        => 1,
                'is_active'           => 1,
                'comment'             => __('Created from PNCP (Portal Nacional de Contratações Públicas)', 'pncp'),
            ]);
            if ($id === 0) {
                $this->lastErrors[] = sprintf(__('Could not create supplier %s', 'pncp'), Util::formatNi($ni));

                return 0;
            }
        }

        $DB->updateOrInsert('glpi_plugin_pncp_supplierlinks', ['suppliers_id' => $id, 'date_mod' => date('Y-m-d H:i:s')], ['ni' => $ni]);

        return $id;
    }

    /**
     * Create or update the GLPI contract for one PNCP contract; returns the GLPI contract id.
     * A contract registered by hand (same number/year, corroborated by supplier, process or
     * start date) is linked instead of duplicated; $adoptId forces the link to a given one.
     * On adoption nothing the user already filled in is overwritten.
     */
    public function contract(int $pncpContractId, bool $withSupplier = true, int $adoptId = 0): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $source = new Contract();
        if (!$source->getFromDB($pncpContractId)) {
            return 0;
        }
        $f = $source->fields;
        $entitiesId = (int) $f['entities_id'];

        $suppliersId = $withSupplier ? $this->supplier($f['supplier_ni'], $f['supplier_name'], $entitiesId) : (int) $f['suppliers_id'];

        // Amendments may extend the term: the farthest end date wins.
        $end = $f['end_date'];
        $amendEnd = $DB->request([
            'SELECT' => ['MAX' => 'end_date AS max_end'],
            'FROM'   => 'glpi_plugin_pncp_amendments',
            'WHERE'  => ['plugin_pncp_contracts_id' => $pncpContractId],
        ])->current()['max_end'] ?? null;
        if ($amendEnd !== null && ($end === null || $amendEnd > $end)) {
            $end = $amendEnd;
        }

        $typeId = $f['type_name'] ? (int) Dropdown::import(ContractType::class, ['name' => $f['type_name']]) : 0;
        $lifecycle = $f['lifecycle'] ?: Lifecycle::ACTIVE;
        $input = [
            'name'              => $f['name'],
            'num'               => Util::truncate(trim($f['number'] . '/' . $f['year'], '/'), 255),
            'contracttypes_id'  => max(0, $typeId),
            'states_id'         => Lifecycle::stateId($lifecycle),
            'begin_date'        => $f['begin_date'],
            'duration'          => self::months($f['begin_date'], $end),
            'accounting_number' => Util::truncate($f['process'], 255),
            'comment'           => $this->contractComment($f, $end),
        ];

        $contract = new GlpiContract();
        $snapshot = json_decode((string) ($f['export_snapshot'] ?? ''), true) ?: [];
        $linkedId = $adoptId > 0 ? $adoptId : (int) $f['contracts_id'];
        $exists = $linkedId > 0 && $contract->getFromDB($linkedId) && !$contract->fields['is_deleted'];
        $adopting = false;
        if ($adoptId > 0) {
            if (!$exists) {
                $this->lastErrors[] = sprintf(__('Could not create contract %s', 'pncp'), $f['control_number']);

                return 0;
            }
            $adopting = true;
        } elseif (!$exists) {
            $found = array_values(array_filter($this->findExisting($f), static fn($c) => $c['score'] > 0));
            if (count($found) === 1 && $contract->getFromDB($found[0]['id'])) {
                $exists = $adopting = true;
            }
        }
        if ($adopting) {
            // Whatever is already filled in belongs to the user: a sentinel that never equals the current value.
            $snapshot = ['_adopted' => 1];
            foreach ($input as $field => $value) {
                $current = (string) ($contract->fields[$field] ?? '');
                if (!in_array($current, ['', '0'], true) && $current !== (string) $value) {
                    $snapshot[$field] = "\0manual";
                }
            }
        }
        $keepsManual = $adopting || !empty($snapshot['_adopted']);
        if ($exists) {
            // A field edited in GLPI since our last write (current value differs from what
            // we wrote) belongs to the user from now on: it is never overwritten again.
            $update = [];
            foreach ($input as $field => $value) {
                $current = $contract->fields[$field] ?? null;
                $ours = !array_key_exists($field, $snapshot) || (string) $snapshot[$field] === (string) $current;
                if ($ours && (string) $current !== (string) $value) {
                    $update[$field] = $value;
                }
                $snapshot[$field] = $ours ? $value : ($snapshot[$field] ?? null);
            }
            if ($update !== []) {
                $contract->update(['id' => $contract->getID()] + $update);
            }
            $contractsId = $contract->getID();
            if ($adopting) {
                $this->adopted[] = $contractsId;
            }
        } else {
            $contractsId = (int) $contract->add($input + ['entities_id' => $entitiesId, 'is_recursive' => (int) $f['is_recursive']]);
            if ($contractsId === 0) {
                $this->lastErrors[] = sprintf(__('Could not create contract %s', 'pncp'), $f['control_number']);

                return 0;
            }
            $snapshot = $input;
        }

        $this->contractCost($contractsId, $f, $end, $entitiesId, $keepsManual);
        if ($suppliersId > 0) {
            $link = new Contract_Supplier();
            $hasSupplier = $keepsManual && countElementsInTable(Contract_Supplier::getTable(), ['contracts_id' => $contractsId]) > 0;
            if (!$hasSupplier && !$link->getFromDBByCrit(['contracts_id' => $contractsId, 'suppliers_id' => $suppliersId])) {
                $link->add(['contracts_id' => $contractsId, 'suppliers_id' => $suppliersId]);
            }
        }

        // Files already imported from PNCP (before the contract existed in GLPI, or from its procurement).
        Documents::linkKnownTo($source, $contractsId);

        $DB->update('glpi_plugin_pncp_contracts', [
            'contracts_id'    => $contractsId,
            'suppliers_id'    => $suppliersId,
            'date_export'     => date('Y-m-d H:i:s'),
            'export_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ], ['id' => $pncpContractId]);

        return $contractsId;
    }

    /**
     * Daily: push lifecycle changes (e.g. "expired" reached by the passing of time)
     * to the GLPI contracts copied from PNCP, unless their status was set by hand.
     * Returns the number of GLPI contracts updated.
     */
    public function refreshStates(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $n = 0;
        $rows = $DB->request([
            'SELECT' => ['id', 'contracts_id', 'lifecycle', 'export_snapshot'],
            'FROM'   => 'glpi_plugin_pncp_contracts',
            'WHERE'  => ['contracts_id' => ['>', 0], 'NOT' => ['lifecycle' => null]],
        ]);
        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row['export_snapshot'], true) ?: [];
            $contract = new GlpiContract();
            if (!$contract->getFromDB((int) $row['contracts_id']) || $contract->fields['is_deleted']) {
                continue;
            }
            $wanted = Lifecycle::stateId($row['lifecycle']);
            $current = (int) $contract->fields['states_id'];
            $ours = !array_key_exists('states_id', $snapshot) || (int) $snapshot['states_id'] === $current;
            if (!$ours || $current === $wanted) {
                continue;
            }
            $contract->update(['id' => $contract->getID(), 'states_id' => $wanted]);
            $snapshot['states_id'] = $wanted;
            $DB->update('glpi_plugin_pncp_contracts', ['export_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)], ['id' => $row['id']]);
            $n++;
        }

        return $n;
    }

    /**
     * Export what changed since the last export for one watch.
     * Returns the number of exported records.
     */
    public function exportPending(int $watchesId, bool $contracts, bool $suppliers): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $n = 0;
        if ($contracts) {
            $rows = $DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_plugin_pncp_contracts',
                'WHERE'  => [
                    'plugin_pncp_watches_id' => $watchesId,
                    'OR'                     => [
                        'contracts_id' => 0,
                        'date_export'  => null,
                        new \Glpi\DBAL\QueryExpression('`date_export` < `date_sync`'),
                    ],
                ],
            ]);
            foreach ($rows as $row) {
                try {
                    $n += $this->contract((int) $row['id'], $suppliers) > 0 ? 1 : 0;
                } catch (Throwable $e) {
                    $this->lastErrors[] = $e->getMessage();
                }
            }
        }
        if ($suppliers) {
            // Contract suppliers (item winners of every procurement would be far too many:
            // they are copied on demand, per item or by massive action).
            $rows = $DB->request([
                'SELECT' => ['id', 'supplier_ni', 'supplier_name', 'entities_id', 'suppliers_id'],
                'FROM'   => 'glpi_plugin_pncp_contracts',
                'WHERE'  => ['plugin_pncp_watches_id' => $watchesId, 'suppliers_id' => 0, 'NOT' => ['supplier_ni' => null]],
            ]);
            foreach ($rows as $row) {
                $id = $this->supplier($row['supplier_ni'], $row['supplier_name'], (int) $row['entities_id']);
                if ($id > 0) {
                    $DB->update('glpi_plugin_pncp_contracts', ['suppliers_id' => $id], ['id' => $row['id']]);
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * GLPI contracts that look like the same contract as this PNCP one: same number/year,
     * not deleted, not linked to another PNCP record. "score" counts corroborations
     * (supplier CNPJ, process number, start date); 0 means the number alone matched.
     *
     * @return array<int, array{id: int, name: string, num: string, begin_date: ?string, score: int, reasons: string[]}>
     */
    public function findExisting(array $f): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $want = Util::normalizeNumber(($f['number'] ?? '') . '/' . ($f['year'] ?? ''));
        if ($want === '') {
            return [];
        }
        [$n] = explode('/', $want);
        $taken = [];
        foreach ($DB->request(['SELECT' => 'contracts_id', 'FROM' => 'glpi_plugin_pncp_contracts', 'WHERE' => ['contracts_id' => ['>', 0], 'NOT' => ['id' => (int) ($f['id'] ?? 0)]]]) as $row) {
            $taken[(int) $row['contracts_id']] = true;
        }
        $current = (int) ($f['contracts_id'] ?? 0);
        $ni = Util::ni($f['supplier_ni'] ?? '');
        $process = Util::ni($f['process'] ?? '');
        $found = [];
        $rows = $DB->request([
            'FROM'  => GlpiContract::getTable(),
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0, 'num' => ['LIKE', '%' . $n . '%']]
                + getEntitiesRestrictCriteria(GlpiContract::getTable(), 'entities_id', (int) $f['entities_id'], true),
        ]);
        foreach ($rows as $c) {
            $id = (int) $c['id'];
            if ($id === $current || isset($taken[$id]) || Util::normalizeNumber($c['num']) !== $want) {
                continue;
            }
            $reasons = [];
            if ($ni !== '') {
                $suppliers = $DB->request([
                    'SELECT'    => 'glpi_suppliers.registration_number',
                    'FROM'      => 'glpi_contracts_suppliers',
                    'INNER JOIN' => ['glpi_suppliers' => ['ON' => ['glpi_contracts_suppliers' => 'suppliers_id', 'glpi_suppliers' => 'id']]],
                    'WHERE'     => ['glpi_contracts_suppliers.contracts_id' => $id],
                ]);
                foreach ($suppliers as $sup) {
                    if (Util::ni($sup['registration_number']) === $ni) {
                        $reasons[] = __('Supplier', 'pncp');
                        break;
                    }
                }
            }
            if ($process !== '' && Util::ni($c['accounting_number']) === $process) {
                $reasons[] = __('Process', 'pncp');
            }
            if (!empty($f['begin_date']) && $c['begin_date'] === $f['begin_date']) {
                $reasons[] = __('Start date');
            }
            $found[] = [
                'id'         => $id,
                'name'       => (string) $c['name'],
                'num'        => (string) $c['num'],
                'begin_date' => $c['begin_date'],
                'score'      => count($reasons),
                'reasons'    => $reasons,
            ];
        }
        usort($found, static fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

        return $found;
    }

    /**
     * Link a PNCP contract to a GLPI contract registered by hand (merge). Whatever the user already
     * filled in is kept; the empty fields are completed. A GLPI contract previously created by this
     * plugin for the same PNCP record goes to the GLPI trash (restorable), not purged.
     * Returns the GLPI contract id, 0 on failure (see $lastErrors).
     */
    public function link(int $pncpContractId, int $glpiContractId): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $source = new Contract();
        $target = new GlpiContract();
        if (!$source->getFromDB($pncpContractId) || !$target->getFromDB($glpiContractId) || $target->fields['is_deleted'] || !$target->canUpdateItem()) {
            $this->lastErrors[] = __('GLPI contract not found or not editable.', 'pncp');

            return 0;
        }
        if (countElementsInTable('glpi_plugin_pncp_contracts', ['contracts_id' => $glpiContractId, 'NOT' => ['id' => $pncpContractId]]) > 0) {
            $this->lastErrors[] = __('This GLPI contract is already linked to another PNCP contract.', 'pncp');

            return 0;
        }
        $old = (int) $source->fields['contracts_id'];
        $createdByPlugin = trim((string) $source->fields['export_snapshot']) !== '' && !str_contains((string) $source->fields['export_snapshot'], '_adopted');

        $id = $this->contract($pncpContractId, true, $glpiContractId);
        if ($id > 0 && $old > 0 && $old !== $id && $createdByPlugin) {
            $dup = new GlpiContract();
            if ($dup->getFromDB($old) && !$dup->fields['is_deleted'] && $dup->canDeleteItem()) {
                $dup->delete(['id' => $old]);
            }
        }

        return $id;
    }

    /** Whole months covered by [begin, end], rounded up (GLPI stores contract duration in months). */
    public static function months(?string $begin, ?string $end): int
    {
        if (!$begin || !$end || $end < $begin) {
            return 0;
        }
        $diff = (new DateTimeImmutable($begin))->diff((new DateTimeImmutable($end))->modify('+1 day'));
        $months = $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0);

        return max(1, min($months, 120));
    }

    private function contractComment(array $f, ?string $end): string
    {
        $lines = [
            $f['object'] ?? '',
            '',
            sprintf('PNCP: %s', $f['control_number']),
            sprintf('%s: %s', __('Link', 'pncp'), Util::portalUrl('contratos', $f['cnpj'], (int) $f['year'], (int) $f['sequential'])),
        ];
        if ($f['procurement_control_number']) {
            $lines[] = sprintf('%s: %s', __('Source procurement', 'pncp'), $f['procurement_control_number']);
        }
        if ($f['process']) {
            $lines[] = sprintf('%s: %s', __('Process', 'pncp'), $f['process']);
        }
        if ($f['supplier_name']) {
            $lines[] = sprintf('%s: %s (%s)', __('Supplier'), $f['supplier_name'], Util::formatNi($f['supplier_ni']));
        }
        if ($end) {
            $lines[] = sprintf('%s: %s', __('End date'), Util::brDate($end));
        }

        return trim(implode("\n", $lines));
    }

    private function contractCost(int $contractsId, array $f, ?string $end, int $entitiesId, bool $keepsManual = false): void
    {
        if ($f['global_value'] === null) {
            return;
        }
        $name = __('Global value (PNCP)', 'pncp');
        $cost = new ContractCost();
        // Linked to a contract registered by hand: its own costs are kept, ours is not added on top.
        if ($keepsManual && !$cost->getFromDBByCrit(['contracts_id' => $contractsId, 'name' => $name])
            && countElementsInTable(ContractCost::getTable(), ['contracts_id' => $contractsId]) > 0) {
            return;
        }
        $input = [
            'contracts_id' => $contractsId,
            'name'         => $name,
            'cost'         => $f['global_value'],
            'begin_date'   => $f['begin_date'],
            'end_date'     => $end,
            'entities_id'  => $entitiesId,
            'comment'      => 'PNCP ' . $f['control_number'],
        ];
        if ($cost->getFromDBByCrit(['contracts_id' => $contractsId, 'name' => $name])) {
            $cost->update(['id' => $cost->getID()] + $input);
        } else {
            $cost->add($input);
        }
    }
}
