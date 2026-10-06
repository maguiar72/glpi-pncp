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
use GlpiPlugin\Pncp\Sync\Syncer;
use MassiveAction;
use Session;

/** Contratação (tender, direct award, ...) published on PNCP. */
class Procurement extends Record
{
    protected const PORTAL_KIND = 'editais';

    public static function getTypeName($nb = 0)
    {
        return _n('Procurement', 'Procurements', $nb, 'pncp');
    }

    public static function getIcon()
    {
        return 'ti ti-gavel';
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $tab = $this->baseSearchOptions();
        array_push(
            $tab,
            ['id' => 20, 'table' => $t, 'field' => 'number', 'name' => __('Number'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 21, 'table' => $t, 'field' => 'modality_name', 'name' => __('Modality', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 22, 'table' => $t, 'field' => 'status_name', 'name' => __('Status'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 23, 'table' => $t, 'field' => 'estimated_value', 'name' => __('Estimated value', 'pncp'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => 24, 'table' => $t, 'field' => 'awarded_value', 'name' => __('Awarded value', 'pncp'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => 25, 'table' => $t, 'field' => 'date_publication', 'name' => __('Publication date', 'pncp'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 26, 'table' => $t, 'field' => 'date_proposal_end', 'name' => __('Proposals until', 'pncp'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 27, 'table' => $t, 'field' => 'process', 'name' => __('Process', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 28, 'table' => $t, 'field' => 'is_srp', 'name' => __('Price registration (SRP)', 'pncp'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => 29, 'table' => $t, 'field' => 'legal_basis', 'name' => __('Legal basis', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 30, 'table' => $t, 'field' => 'date_items_sync', 'name' => __('Items synchronized on', 'pncp'), 'datatype' => 'datetime', 'massiveaction' => false],
        );

        return $tab;
    }

    public function showForm($ID, array $options = [])
    {
        /** @var \DBmysql $DB */
        global $DB;

        $this->initForm($ID, $options);
        $control = $this->fields['control_number'];
        $this->renderDetail('procurement.html.twig', [
            'items'        => iterator_to_array($DB->request([
                'FROM'  => 'glpi_plugin_pncp_procurementitems',
                'WHERE' => ['plugin_pncp_procurements_id' => $ID],
                'ORDER' => 'item_number',
            ]), false),
            'contracts'    => iterator_to_array($DB->request([
                'SELECT' => ['id', 'name', 'supplier_name', 'supplier_ni', 'global_value', 'end_date', 'contracts_id'],
                'FROM'   => Contract::getTable(),
                'WHERE'  => ['procurement_control_number' => $control],
            ]), false),
            'pricerecords' => iterator_to_array($DB->request([
                'SELECT' => ['id', 'name', 'begin_date', 'end_date', 'is_cancelled'],
                'FROM'   => PriceRecord::getTable(),
                'WHERE'  => ['procurement_control_number' => $control],
            ]), false),
            'can_update'   => self::canUpdate(),
        ]);

        return true;
    }

    /** Re-read this procurement, its items and results from the portal now. */
    public function refreshFromPortal(int $budget): int
    {
        $api = Config::api($budget);
        $raw = $api->procurement($this->fields['cnpj'], (int) $this->fields['year'], (int) $this->fields['sequential']);
        if ($raw === null) {
            return 0;
        }
        $store = new Store((int) $this->fields['entities_id'], (int) $this->fields['is_recursive'], (int) $this->fields['plugin_pncp_watches_id']);
        $store->procurement($raw);
        $this->getFromDB($this->getID());

        return (new Syncer($api))->fetchItems($this->getID(), $this->fields['cnpj'], (int) $this->fields['year'], (int) $this->fields['sequential'], $this->fields['date_update']);
    }

    public function getSpecificMassiveActions($checkitem = null)
    {
        $actions = parent::getSpecificMassiveActions($checkitem);
        if (Session::haveRight(self::$rightname, UPDATE)) {
            $actions[self::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'export_suppliers'] = '<i class="ti ti-truck"></i>' . __('Copy winning suppliers to GLPI', 'pncp');
        }

        return $actions;
    }

    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids)
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($ma->getAction() !== 'export_suppliers') {
            parent::processMassiveActionsForOneItemtype($ma, $item, $ids);

            return;
        }
        $exporter = new Exporter();
        foreach ($ids as $id) {
            if (!$item->getFromDB($id)) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                continue;
            }
            $winners = $DB->request([
                'SELECT'   => ['supplier_ni', 'supplier_name'],
                'DISTINCT' => true,
                'FROM'     => 'glpi_plugin_pncp_procurementitems',
                'WHERE'    => ['plugin_pncp_procurements_id' => $id, 'NOT' => ['supplier_ni' => null]],
            ]);
            foreach ($winners as $w) {
                $exporter->supplier($w['supplier_ni'], $w['supplier_name'], (int) $item->fields['entities_id']);
            }
            $ma->itemDone($item->getType(), $id, $exporter->lastErrors === [] ? MassiveAction::ACTION_OK : MassiveAction::ACTION_KO);
        }
    }

    public function cleanDBonPurge()
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete('glpi_plugin_pncp_procurementitems', ['plugin_pncp_procurements_id' => $this->getID()]);
    }
}
