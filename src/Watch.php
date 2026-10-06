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

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pncp\Sync\Syncer;
use Html;
use Session;

/**
 * A monitored scope: one organization (CNPJ), optionally narrowed to one
 * administrative unit, kept up to date by the automatic action.
 */
class Watch extends CommonDBTM
{
    public const CRON_SYNC = 'pncpsync';

    public static $rightname = Install::RIGHT;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Monitoring', 'Monitorings', $nb, 'pncp');
    }

    public static function getIcon()
    {
        return 'ti ti-radar';
    }


    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(self::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);

        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof self && $item->getID() > 0) {
            return [
                1 => self::createTabEntry(__('Imported data', 'pncp'), 0, null, 'ti ti-database'),
                2 => self::createTabEntry(__('Synchronization log', 'pncp'), 0, null, 'ti ti-list-details'),
            ];
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof self) {
            return false;
        }
        if ($tabnum == 1) {
            $item->showCounts();
        } else {
            $item->showLog();
        }

        return true;
    }

    public function prepareInputForAdd($input)
    {
        return $this->checkInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->checkInput($input);
    }

    private function checkInput(array $input): array|false
    {
        if (array_key_exists('cnpj', $input)) {
            $input['cnpj'] = Util::ni($input['cnpj']);
            if (!Util::isValidCnpj($input['cnpj'])) {
                Session::addMessageAfterRedirect(__('Invalid CNPJ', 'pncp'), false, ERROR);

                return false;
            }
            if (empty($input['name']) && empty($this->fields['name'])) {
                $input['name'] = Util::formatNi($input['cnpj']);
            }
        }
        if (array_key_exists('unit_code', $input)) {
            $input['unit_code'] = trim((string) $input['unit_code']);
        }
        if (isset($input['date_begin']) && $input['date_begin'] !== '' && $input['date_begin'] < '2021-01-01') {
            $input['date_begin'] = '2021-01-01';
        }
        // Changing the scope invalidates the cursors: the next run starts over (idempotent upserts).
        if (
            !$this->isNewItem()
            && ((isset($input['cnpj']) && $input['cnpj'] !== $this->fields['cnpj'])
                || (isset($input['unit_code']) && $input['unit_code'] !== $this->fields['unit_code'])
                || (isset($input['date_begin']) && $input['date_begin'] !== $this->fields['date_begin']))
        ) {
            $input['cursors'] = null;
        }

        return $input;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        if ($this->isNewItem()) {
            foreach (['cnpj', 'unit_code', 'name'] as $key) {
                // The form is loaded through the GLPI tab ajax: the values arrive in $options, not in the page URL.
                $value = $options[$key] ?? $_GET[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $this->fields[$key] = $value;
                }
            }
            $this->fields['date_begin'] ??= date('Y') . '-01-01';
        }
        TemplateRenderer::getInstance()->display('@pncp/watch_form.html.twig', [
            'item'      => $this,
            'params'    => $options,
            'cursors'   => json_decode((string) ($this->fields['cursors'] ?? ''), true) ?: [],
            'units'     => $this->knownUnits(),
            'can_sync'  => !$this->isNewItem() && self::canUpdate(),
        ]);

        return true;
    }

    /** @return array<int, array> units cached for this CNPJ, grouped by code */
    public function knownUnits(): array
    {
        return empty($this->fields['cnpj']) ? [] : Store::groupedUnits(Util::ni($this->fields['cnpj']));
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();

        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink'],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'cnpj', 'name' => __('Organization CNPJ', 'pncp'), 'datatype' => 'string'],
            ['id' => 4, 'table' => $t, 'field' => 'unit_code', 'name' => __('Unit code', 'pncp'), 'datatype' => 'string'],
            ['id' => 5, 'table' => $t, 'field' => 'date_begin', 'name' => __('Collect from', 'pncp'), 'datatype' => 'date'],
            ['id' => 6, 'table' => $t, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'],
            ['id' => 7, 'table' => $t, 'field' => 'last_sync', 'name' => __('Last synchronization', 'pncp'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'last_status', 'name' => __('Status'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 9, 'table' => $t, 'field' => 'auto_suppliers', 'name' => __('Copy suppliers automatically', 'pncp'), 'datatype' => 'bool'],
            ['id' => 10, 'table' => $t, 'field' => 'auto_contracts', 'name' => __('Copy contracts automatically', 'pncp'), 'datatype' => 'bool'],
            ['id' => 16, 'table' => $t, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text'],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => \Entity::getTypeName(1), 'datatype' => 'dropdown'],
        ];
    }

    public function cleanDBonPurge()
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Imported data is kept (it may be linked to GLPI contracts); only detached from the watch.
        foreach (['procurements', 'contracts', 'pricerecords', 'pcaitems'] as $t) {
            $DB->update("glpi_plugin_pncp_$t", ['plugin_pncp_watches_id' => 0], ['plugin_pncp_watches_id' => $this->getID()]);
        }
        $DB->delete('glpi_plugin_pncp_synclogs', ['plugin_pncp_watches_id' => $this->getID()]);
    }

    // ── synchronization ───────────────────────────────────────────────────

    /**
     * Synchronize this scope now, within $budget seconds. A named DB lock keeps
     * the automatic action and a manual run from working on the same scope at once.
     */
    public function synchronize(int $budget): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $lock = 'plugin_pncp_watch_' . $this->getID();
        if (!$DB->getLock($lock)) {
            return [
                'status'  => Syncer::STATUS_BUSY,
                'records' => 0,
                'message' => __('A synchronization of this monitoring is already running.', 'pncp'),
            ];
        }
        try {
            return (new Syncer(Config::api($budget)))->run($this);
        } finally {
            $DB->releaseLock($lock);
        }
    }

    /**
     * Whether this watch needs a run: a backlog left by a partial run, or the
     * configured interval since the last complete synchronization.
     */
    public function isDue(): bool
    {
        if (empty($this->fields['last_sync']) || $this->fields['last_status'] !== Syncer::STATUS_OK) {
            return true;
        }

        return strtotime($this->fields['last_sync']) <= time() - 3600 * (int) Config::get('sync_interval');
    }

    public static function cronInfo($name)
    {
        return ['description' => __('Synchronize monitored PNCP scopes', 'pncp')];
    }

    public static function cronPncpsync(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $deadline = microtime(true) + (int) Config::get('cron_budget');
        $worked = false;
        $partial = false;

        // Once a day: lifecycle depends on dates ("expired" arrives with time alone).
        $today = date('Y-m-d');
        $last = \Config::getConfigurationValues(Config::CONTEXT, ['lifecycle_date'])['lifecycle_date'] ?? '';
        if ($last !== $today) {
            $changed = Lifecycle::refresh();
            $states = (new Exporter())->refreshStates();
            \Config::setConfigurationValues(Config::CONTEXT, ['lifecycle_date' => $today]);
            $task->log(sprintf('Lifecycle: %d PNCP contract(s) changed, %d GLPI contract(s) updated', $changed, $states));
            $worked = $worked || $changed > 0 || $states > 0;
        }
        $rows = $DB->request([
            'SELECT' => 'id',
            'FROM'   => self::getTable(),
            'WHERE'  => ['is_active' => 1],
            'ORDER'  => [new \Glpi\DBAL\QueryExpression('`last_sync` IS NOT NULL'), 'last_sync ASC'],
        ]);
        foreach ($rows as $row) {
            $left = (int) floor($deadline - microtime(true));
            if ($left < 20) {
                $partial = true;
                break;
            }
            $watch = new self();
            if (!$watch->getFromDB((int) $row['id']) || !$watch->isDue()) {
                continue;
            }
            $result = $watch->synchronize($left);
            if ($result['status'] === Syncer::STATUS_BUSY) {
                continue;
            }
            $worked = true;
            $task->addVolume($result['records']);
            $task->log(sprintf('%s: %s (%d)%s', $watch->fields['name'], $result['status'], $result['records'], $result['message'] ? ' — ' . $result['message'] : ''));
            if ($result['status'] !== Syncer::STATUS_OK) {
                $partial = true;
            }
        }

        return !$worked ? 0 : ($partial ? -1 : 1);
    }

    // ── tabs ──────────────────────────────────────────────────────────────

    private function showCounts(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $id = $this->getID();
        $count = static fn(string $table, array $where = []) => (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => $table,
            'WHERE' => ['plugin_pncp_watches_id' => $id] + $where,
        ])->current()['c'];

        $rows = [
            [Procurement::class, $count('glpi_plugin_pncp_procurements')],
            [Contract::class, $count('glpi_plugin_pncp_contracts')],
            [PriceRecord::class, $count('glpi_plugin_pncp_pricerecords')],
            [PcaItem::class, $count('glpi_plugin_pncp_pcaitems')],
        ];
        $pendingItems = $count('glpi_plugin_pncp_procurements', ['OR' => [
            'items_version' => null,
            new \Glpi\DBAL\QueryExpression('`items_version` <> `date_update`'),
        ]]);
        $exported = $count('glpi_plugin_pncp_contracts', ['contracts_id' => ['>', 0]]);

        TemplateRenderer::getInstance()->display('@pncp/watch_counts.html.twig', [
            'rows'          => array_map(static fn($r) => [
                'label' => $r[0]::getTypeName(2),
                'count' => $r[1],
                'url'   => $r[0]::getSearchURL() . '?' . http_build_query(['criteria' => [['field' => 10, 'searchtype' => 'equals', 'value' => $id]], 'reset' => 'reset']),
            ], $rows),
            'pending_items' => $pendingItems,
            'exported'      => $exported,
        ]);
    }

    private function showLog(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        TemplateRenderer::getInstance()->display('@pncp/watch_log.html.twig', [
            'rows' => iterator_to_array($DB->request([
                'FROM'  => 'glpi_plugin_pncp_synclogs',
                'WHERE' => ['plugin_pncp_watches_id' => $this->getID()],
                'ORDER' => 'id DESC',
                'LIMIT' => 200,
            ]), false),
        ]);
    }
}
