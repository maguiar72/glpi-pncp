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

use CronTask;
use DBConnection;
use Migration;
use ProfileRight;

/**
 * Database schema, rights and automatic actions.
 *
 * Every table keeps the raw JSON returned by the portal (`raw`) as the source
 * of truth; the other columns are projections used for search and display.
 * Records are keyed by the PNCP control number, so re-importing is idempotent.
 */
final class Install
{
    public const RIGHT = 'plugin_pncp';

    /** @return array<string, string> table => column definitions (without id/keys) */
    private static function schema(): array
    {
        $date_mod = "`date_mod` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL";

        return [
            // A monitored scope: one organization (CNPJ), optionally narrowed to one unit.
            'glpi_plugin_pncp_watches' => "
                `entities_id` int {SIGN} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `name` varchar(255) DEFAULT NULL,
                `cnpj` varchar(14) NOT NULL DEFAULT '',
                `unit_code` varchar(20) NOT NULL DEFAULT '',
                `date_begin` date DEFAULT NULL,
                `is_active` tinyint NOT NULL DEFAULT '1',
                `sync_procurements` tinyint NOT NULL DEFAULT '1',
                `sync_items` tinyint NOT NULL DEFAULT '1',
                `sync_contracts` tinyint NOT NULL DEFAULT '1',
                `sync_amendments` tinyint NOT NULL DEFAULT '1',
                `sync_commitments` tinyint NOT NULL DEFAULT '1',
                `sync_pricerecords` tinyint NOT NULL DEFAULT '1',
                `sync_pca` tinyint NOT NULL DEFAULT '1',
                `auto_suppliers` tinyint NOT NULL DEFAULT '1',
                `auto_contracts` tinyint NOT NULL DEFAULT '0',
                `cursors` text,
                `last_sync` timestamp NULL DEFAULT NULL,
                `last_status` varchar(20) NOT NULL DEFAULT '',
                `last_message` text,
                `comment` text,
                $date_mod,
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`),
                KEY `cnpj` (`cnpj`)",

            // Units (unidades administrativas) of an organization, with the portal internal ids
            // needed by the full-text search filters. The portal registers some units twice,
            // with and without leading zeros ("012345" and "12345", different ids): one row per
            // spelling (`pncp_code`), grouped by the normalized `code`.
            'glpi_plugin_pncp_units' => "
                `cnpj` varchar(14) NOT NULL DEFAULT '',
                `org_name` varchar(255) DEFAULT NULL,
                `org_pncp_id` int {SIGN} NOT NULL DEFAULT '0',
                `code` varchar(20) NOT NULL DEFAULT '',
                `pncp_code` varchar(20) DEFAULT NULL,
                `pncp_id` int {SIGN} NOT NULL DEFAULT '0',
                `name` varchar(255) DEFAULT NULL,
                `town` varchar(255) DEFAULT NULL,
                `state` varchar(2) DEFAULT NULL,
                `ibge_code` varchar(7) DEFAULT NULL,
                `is_active` tinyint NOT NULL DEFAULT '1',
                $date_mod,
                UNIQUE KEY `unicity` (`cnpj`, `pncp_code`),
                KEY `code` (`cnpj`, `code`)",

            // Contratações (procurements: tenders, direct awards...).
            'glpi_plugin_pncp_procurements' => "
                `entities_id` int {SIGN} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `plugin_pncp_watches_id` int {SIGN} NOT NULL DEFAULT '0',
                `name` varchar(255) DEFAULT NULL,
                `control_number` varchar(50) NOT NULL DEFAULT '',
                `cnpj` varchar(14) NOT NULL DEFAULT '',
                `org_name` varchar(255) DEFAULT NULL,
                `unit_code` varchar(20) DEFAULT NULL,
                `unit_name` varchar(255) DEFAULT NULL,
                `year` int NOT NULL DEFAULT '0',
                `sequential` int NOT NULL DEFAULT '0',
                `number` varchar(50) DEFAULT NULL,
                `process` varchar(255) DEFAULT NULL,
                `modality_id` int {SIGN} NOT NULL DEFAULT '0',
                `modality_name` varchar(100) DEFAULT NULL,
                `status_name` varchar(100) DEFAULT NULL,
                `legal_basis` varchar(255) DEFAULT NULL,
                `object` text,
                `estimated_value` decimal(20,4) DEFAULT NULL,
                `awarded_value` decimal(20,4) DEFAULT NULL,
                `is_srp` tinyint NOT NULL DEFAULT '0',
                `date_publication` timestamp NULL DEFAULT NULL,
                `date_proposal_end` timestamp NULL DEFAULT NULL,
                `date_update` timestamp NULL DEFAULT NULL,
                `source_url` varchar(500) DEFAULT NULL,
                `items_version` varchar(30) DEFAULT NULL,
                `date_items_sync` timestamp NULL DEFAULT NULL,
                `raw` longtext,
                `date_sync` timestamp NULL DEFAULT NULL,
                $date_mod,
                UNIQUE KEY `control_number` (`control_number`),
                KEY `entities_id` (`entities_id`),
                KEY `plugin_pncp_watches_id` (`plugin_pncp_watches_id`),
                KEY `cnpj_unit` (`cnpj`, `unit_code`),
                KEY `year` (`year`),
                KEY `date_update` (`date_update`)",

            // Items of a procurement, with the awarded result (winner and price) when there is one.
            'glpi_plugin_pncp_procurementitems' => "
                `plugin_pncp_procurements_id` int {SIGN} NOT NULL DEFAULT '0',
                `item_number` int NOT NULL DEFAULT '0',
                `name` text,
                `material_or_service` varchar(50) DEFAULT NULL,
                `category_name` varchar(255) DEFAULT NULL,
                `measure_unit` varchar(100) DEFAULT NULL,
                `quantity` decimal(20,4) DEFAULT NULL,
                `estimated_unit_value` decimal(20,4) DEFAULT NULL,
                `estimated_total_value` decimal(20,4) DEFAULT NULL,
                `status_name` varchar(100) DEFAULT NULL,
                `has_result` tinyint NOT NULL DEFAULT '0',
                `supplier_ni` varchar(20) DEFAULT NULL,
                `supplier_name` varchar(255) DEFAULT NULL,
                `supplier_size` varchar(100) DEFAULT NULL,
                `awarded_quantity` decimal(20,4) DEFAULT NULL,
                `awarded_unit_value` decimal(20,4) DEFAULT NULL,
                `awarded_total_value` decimal(20,4) DEFAULT NULL,
                `date_result` timestamp NULL DEFAULT NULL,
                `date_update` timestamp NULL DEFAULT NULL,
                `raw` longtext,
                $date_mod,
                UNIQUE KEY `unicity` (`plugin_pncp_procurements_id`, `item_number`),
                KEY `supplier_ni` (`supplier_ni`)",

            // Contracts and commitment notes (empenhos) published on PNCP.
            'glpi_plugin_pncp_contracts' => "
                `entities_id` int {SIGN} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `plugin_pncp_watches_id` int {SIGN} NOT NULL DEFAULT '0',
                `name` varchar(255) DEFAULT NULL,
                `control_number` varchar(50) NOT NULL DEFAULT '',
                `procurement_control_number` varchar(50) DEFAULT NULL,
                `cnpj` varchar(14) NOT NULL DEFAULT '',
                `org_name` varchar(255) DEFAULT NULL,
                `unit_code` varchar(20) DEFAULT NULL,
                `unit_name` varchar(255) DEFAULT NULL,
                `number` varchar(100) DEFAULT NULL,
                `year` int NOT NULL DEFAULT '0',
                `sequential` int NOT NULL DEFAULT '0',
                `type_name` varchar(100) DEFAULT NULL,
                `category_name` varchar(100) DEFAULT NULL,
                `process` varchar(255) DEFAULT NULL,
                `supplier_ni` varchar(20) DEFAULT NULL,
                `supplier_name` varchar(255) DEFAULT NULL,
                `object` text,
                `initial_value` decimal(20,4) DEFAULT NULL,
                `global_value` decimal(20,4) DEFAULT NULL,
                `date_signature` date DEFAULT NULL,
                `begin_date` date DEFAULT NULL,
                `end_date` date DEFAULT NULL,
                `date_publication` timestamp NULL DEFAULT NULL,
                `date_update` timestamp NULL DEFAULT NULL,
                `amendments_version` varchar(30) DEFAULT NULL,
                `commitments_version` varchar(30) DEFAULT NULL,
                `lifecycle` varchar(20) DEFAULT NULL,
                `contracts_id` int {SIGN} NOT NULL DEFAULT '0',
                `suppliers_id` int {SIGN} NOT NULL DEFAULT '0',
                `date_export` timestamp NULL DEFAULT NULL,
                `export_snapshot` text,
                `raw` longtext,
                `date_sync` timestamp NULL DEFAULT NULL,
                $date_mod,
                UNIQUE KEY `control_number` (`control_number`),
                KEY `entities_id` (`entities_id`),
                KEY `plugin_pncp_watches_id` (`plugin_pncp_watches_id`),
                KEY `cnpj_unit` (`cnpj`, `unit_code`),
                KEY `procurement_control_number` (`procurement_control_number`),
                KEY `lifecycle` (`lifecycle`),
                KEY `supplier_ni` (`supplier_ni`),
                KEY `contracts_id` (`contracts_id`),
                KEY `suppliers_id` (`suppliers_id`),
                KEY `end_date` (`end_date`)",

            // Termos aditivos / apostilamentos of a contract.
            'glpi_plugin_pncp_amendments' => "
                `plugin_pncp_contracts_id` int {SIGN} NOT NULL DEFAULT '0',
                `sequential` int NOT NULL DEFAULT '0',
                `name` varchar(255) DEFAULT NULL,
                `type_name` varchar(100) DEFAULT NULL,
                `object` text,
                `added_value` decimal(20,4) DEFAULT NULL,
                `global_value` decimal(20,4) DEFAULT NULL,
                `date_signature` date DEFAULT NULL,
                `begin_date` date DEFAULT NULL,
                `end_date` date DEFAULT NULL,
                `raw` longtext,
                $date_mod,
                UNIQUE KEY `unicity` (`plugin_pncp_contracts_id`, `sequential`)",

            // Empenhos (commitment notes) linked to a contract.
            'glpi_plugin_pncp_commitments' => "
                `plugin_pncp_contracts_id` int {SIGN} NOT NULL DEFAULT '0',
                `sequential` int NOT NULL DEFAULT '0',
                `number` varchar(50) DEFAULT NULL,
                `status_name` varchar(100) DEFAULT NULL,
                `status_date` timestamp NULL DEFAULT NULL,
                `issue_date` date DEFAULT NULL,
                `creditor_ni` varchar(20) DEFAULT NULL,
                `creditor_name` varchar(255) DEFAULT NULL,
                `expense_nature` varchar(20) DEFAULT NULL,
                `internal_plan` varchar(50) DEFAULT NULL,
                `total_value` decimal(20,4) DEFAULT NULL,
                `raw` longtext,
                $date_mod,
                UNIQUE KEY `unicity` (`plugin_pncp_contracts_id`, `sequential`),
                KEY `number` (`number`)",

            // Atas de registro de preços (price registration records).
            'glpi_plugin_pncp_pricerecords' => "
                `entities_id` int {SIGN} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `plugin_pncp_watches_id` int {SIGN} NOT NULL DEFAULT '0',
                `name` varchar(255) DEFAULT NULL,
                `control_number` varchar(60) NOT NULL DEFAULT '',
                `procurement_control_number` varchar(50) DEFAULT NULL,
                `cnpj` varchar(14) NOT NULL DEFAULT '',
                `org_name` varchar(255) DEFAULT NULL,
                `unit_code` varchar(20) DEFAULT NULL,
                `unit_name` varchar(255) DEFAULT NULL,
                `number` varchar(50) DEFAULT NULL,
                `year` int NOT NULL DEFAULT '0',
                `object` text,
                `is_cancelled` tinyint NOT NULL DEFAULT '0',
                `date_signature` date DEFAULT NULL,
                `begin_date` date DEFAULT NULL,
                `end_date` date DEFAULT NULL,
                `date_publication` timestamp NULL DEFAULT NULL,
                `date_update` timestamp NULL DEFAULT NULL,
                `raw` longtext,
                `date_sync` timestamp NULL DEFAULT NULL,
                $date_mod,
                UNIQUE KEY `control_number` (`control_number`),
                KEY `entities_id` (`entities_id`),
                KEY `plugin_pncp_watches_id` (`plugin_pncp_watches_id`),
                KEY `cnpj_unit` (`cnpj`, `unit_code`),
                KEY `procurement_control_number` (`procurement_control_number`),
                KEY `end_date` (`end_date`)",

            // Items of the annual procurement plan (PCA), flattened.
            'glpi_plugin_pncp_pcaitems' => "
                `entities_id` int {SIGN} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '0',
                `plugin_pncp_watches_id` int {SIGN} NOT NULL DEFAULT '0',
                `uid` varchar(80) NOT NULL DEFAULT '',
                `pca_id` varchar(60) DEFAULT NULL,
                `year` int NOT NULL DEFAULT '0',
                `cnpj` varchar(14) NOT NULL DEFAULT '',
                `unit_code` varchar(20) DEFAULT NULL,
                `unit_name` varchar(255) DEFAULT NULL,
                `item_number` int NOT NULL DEFAULT '0',
                `name` text,
                `category_name` varchar(255) DEFAULT NULL,
                `catalog_class` varchar(255) DEFAULT NULL,
                `group_name` varchar(255) DEFAULT NULL,
                `quantity` decimal(20,4) DEFAULT NULL,
                `unit_value` decimal(20,4) DEFAULT NULL,
                `total_value` decimal(20,4) DEFAULT NULL,
                `date_desired` date DEFAULT NULL,
                `date_update` timestamp NULL DEFAULT NULL,
                `raw` longtext,
                `date_sync` timestamp NULL DEFAULT NULL,
                $date_mod,
                UNIQUE KEY `uid` (`uid`),
                KEY `entities_id` (`entities_id`),
                KEY `plugin_pncp_watches_id` (`plugin_pncp_watches_id`),
                KEY `cnpj_year` (`cnpj`, `year`)",

            // PNCP supplier identifier (CNPJ/CPF digits) => GLPI supplier.
            'glpi_plugin_pncp_supplierlinks' => "
                `ni` varchar(20) NOT NULL DEFAULT '',
                `suppliers_id` int {SIGN} NOT NULL DEFAULT '0',
                $date_mod,
                UNIQUE KEY `ni` (`ni`),
                KEY `suppliers_id` (`suppliers_id`)",

            // Files published on PNCP imported as GLPI documents (one per portal file).
            'glpi_plugin_pncp_documents' => "
                `source_key` varchar(190) NOT NULL DEFAULT '',
                `url` text,
                `documents_id` int {SIGN} NOT NULL DEFAULT '0',
                $date_mod,
                UNIQUE KEY `source_key` (`source_key`),
                KEY `documents_id` (`documents_id`)",

            // One line per synchronization phase run.
            'glpi_plugin_pncp_synclogs' => "
                `plugin_pncp_watches_id` int {SIGN} NOT NULL DEFAULT '0',
                `date` timestamp NULL DEFAULT NULL,
                `phase` varchar(30) NOT NULL DEFAULT '',
                `window_begin` date DEFAULT NULL,
                `window_end` date DEFAULT NULL,
                `records` int NOT NULL DEFAULT '0',
                `requests` int NOT NULL DEFAULT '0',
                `status` varchar(20) NOT NULL DEFAULT '',
                `message` text,
                KEY `plugin_pncp_watches_id` (`plugin_pncp_watches_id`),
                KEY `date` (`date`)",
        ];
    }

    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        foreach (self::schema() as $table => $columns) {
            if ($DB->tableExists($table)) {
                continue;
            }
            $migration->displayMessage("Creating $table");
            $columns = str_replace('{SIGN}', $sign, $columns);
            $DB->doQuery(
                "CREATE TABLE `$table` (
                    `id` int $sign NOT NULL AUTO_INCREMENT,
                    $columns,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC",
            );
        }

        // Upgrade of installs made before 0.2.0 (tables above already existed).
        if (!$DB->fieldExists('glpi_plugin_pncp_contracts', 'lifecycle')) {
            $migration->addField('glpi_plugin_pncp_contracts', 'lifecycle', "varchar(20) DEFAULT NULL", ['after' => 'amendments_version']);
            $migration->addKey('glpi_plugin_pncp_contracts', 'lifecycle');
        }
        if (!$DB->fieldExists('glpi_plugin_pncp_contracts', 'export_snapshot')) {
            $migration->addField('glpi_plugin_pncp_contracts', 'export_snapshot', 'text', ['after' => 'date_export']);
        }
        // Upgrade of installs made before 0.3.0 (commitments).
        if (!$DB->fieldExists('glpi_plugin_pncp_contracts', 'commitments_version')) {
            $migration->addField('glpi_plugin_pncp_contracts', 'commitments_version', 'varchar(30) DEFAULT NULL', ['after' => 'amendments_version']);
        }
        if (!$DB->fieldExists('glpi_plugin_pncp_watches', 'sync_commitments')) {
            $migration->addField('glpi_plugin_pncp_watches', 'sync_commitments', "tinyint NOT NULL DEFAULT '1'", ['after' => 'sync_amendments']);
        }
        $migration->changeField('glpi_plugin_pncp_watches', 'auto_suppliers', 'auto_suppliers', "tinyint NOT NULL DEFAULT '1'");

        if (countElementsInTable('glpi_profilerights', ['name' => self::RIGHT]) > 0) {
            return; // rights, list columns and the automatic action exist: this was an upgrade
        }

        ProfileRight::addProfileRights([self::RIGHT]);
        // Super-admin profiles get every right on first install; others stay at 0
        // until an administrator grants them in the profile tab.
        $DB->update(
            'glpi_profilerights',
            ['rights' => READ | UPDATE | CREATE | PURGE],
            ['name' => self::RIGHT, 'profiles_id' => self::superAdminProfiles()],
        );

        self::defaultDisplayPreferences();

        CronTask::register(
            Watch::class,
            Watch::CRON_SYNC,
            5 * MINUTE_TIMESTAMP,
            [
                'mode'    => CronTask::MODE_EXTERNAL,
                'state'   => CronTask::STATE_WAITING,
                'comment' => 'Incremental synchronization of monitored PNCP scopes',
            ],
        );
    }

    public static function uninstall(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (array_keys(self::schema()) as $table) {
            $DB->dropTable($table, true);
        }
        ProfileRight::deleteProfileRights([self::RIGHT]);
        CronTask::unregister('pncp');
        \Config::deleteConfigurationValues(Config::CONTEXT);
        $DB->delete('glpi_displaypreferences', ['itemtype' => ['LIKE', 'GlpiPlugin\\\\Pncp\\\\%']]);
    }

    /**
     * Default list columns (search option ids), only for lists nobody configured yet.
     * Names are short titles: the object, unit, values and dates are columns.
     */
    public static function defaultDisplayPreferences(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $defaults = [
            Watch::class       => [3, 4, 5, 6, 7, 8],
            Procurement::class => [9, 7, 23, 25, 22],
            Contract::class    => [33, 22, 9, 24, 25, 26, 30],
            PriceRecord::class => [9, 7, 21, 22, 23],
            PcaItem::class     => [8, 7, 21, 26, 27],
        ];
        foreach ($defaults as $itemtype => $columns) {
            if (countElementsInTable('glpi_displaypreferences', ['itemtype' => $itemtype]) > 0) {
                continue;
            }
            foreach ($columns as $rank => $num) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype'  => $itemtype,
                    'num'       => $num,
                    'rank'      => $rank + 1,
                    'users_id'  => 0,
                    'interface' => 'central',
                ]);
            }
        }
    }

    /** @return int[] */
    private static function superAdminProfiles(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        $rows = $DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config'],
        ]);
        foreach ($rows as $row) {
            if (((int) $row['rights'] & UPDATE) === UPDATE) {
                $ids[] = (int) $row['profiles_id'];
            }
        }

        return $ids === [] ? [0] : $ids;
    }
}
