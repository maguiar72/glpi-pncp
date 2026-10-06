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
use MassiveAction;
use Session;
use Throwable;

/** Contract or commitment note (empenho) published on PNCP. */
class Contract extends Record
{
    protected const PORTAL_KIND = 'contratos';

    public static function getTypeName($nb = 0)
    {
        return _n('PNCP contract', 'PNCP contracts', $nb, 'pncp');
    }

    public static function getIcon()
    {
        return 'ti ti-writing-sign';
    }


    public function rawSearchOptions()
    {
        $t = self::getTable();
        $tab = $this->baseSearchOptions();
        array_push(
            $tab,
            ['id' => 20, 'table' => $t, 'field' => 'number', 'name' => __('Number'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 21, 'table' => $t, 'field' => 'type_name', 'name' => __('Type'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 22, 'table' => $t, 'field' => 'supplier_name', 'name' => __('Supplier'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 23, 'table' => $t, 'field' => 'supplier_ni', 'name' => __('Supplier CNPJ/CPF', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 24, 'table' => $t, 'field' => 'global_value', 'name' => __('Global value', 'pncp'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => 25, 'table' => $t, 'field' => 'begin_date', 'name' => __('Start date'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 26, 'table' => $t, 'field' => 'end_date', 'name' => __('End date'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 27, 'table' => $t, 'field' => 'date_signature', 'name' => __('Signature date', 'pncp'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 28, 'table' => $t, 'field' => 'process', 'name' => __('Process', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 29, 'table' => $t, 'field' => 'procurement_control_number', 'name' => __('Source procurement', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 30, 'table' => 'glpi_contracts', 'field' => 'name', 'name' => __('GLPI contract', 'pncp'), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 31, 'table' => 'glpi_suppliers', 'field' => 'name', 'name' => __('GLPI supplier', 'pncp'), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 32, 'table' => $t, 'field' => 'date_export', 'name' => __('Copied to GLPI on', 'pncp'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 33, 'table' => $t, 'field' => 'lifecycle', 'name' => __('Lifecycle', 'pncp'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
        );

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'lifecycle') {
            $key = is_array($values) ? ($values[$field] ?? null) : $values;

            return htmlescape(Lifecycle::label($key));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'lifecycle') {
            $choices = [];
            foreach (Lifecycle::KEYS as $key) {
                $choices[$key] = Lifecycle::label($key);
            }

            return \Dropdown::showFromArray($name, $choices, ['value' => is_array($values) ? ($values[$field] ?? '') : $values, 'display' => false]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function showForm($ID, array $options = [])
    {
        /** @var \DBmysql $DB */
        global $DB;

        $this->initForm($ID, $options);
        $procurement = null;
        if ($this->fields['procurement_control_number']) {
            $procurement = $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => Procurement::getTable(),
                'WHERE'  => ['control_number' => $this->fields['procurement_control_number']],
            ])->current();
        }
        $this->renderDetail('contract.html.twig', [
            'amendments'  => iterator_to_array($DB->request([
                'FROM'  => 'glpi_plugin_pncp_amendments',
                'WHERE' => ['plugin_pncp_contracts_id' => $ID],
                'ORDER' => 'sequential',
            ]), false),
            'commitments' => iterator_to_array($DB->request([
                'FROM'  => 'glpi_plugin_pncp_commitments',
                'WHERE' => ['plugin_pncp_contracts_id' => $ID],
                'ORDER' => ['issue_date', 'sequential'],
            ]), false),
            'procurement' => $procurement,
            'glpi_contract_url' => $this->fields['contracts_id'] ? \Contract::getFormURLWithID($this->fields['contracts_id']) : null,
            'glpi_supplier_url' => $this->fields['suppliers_id'] ? \Supplier::getFormURLWithID($this->fields['suppliers_id']) : null,
            'supplier_ni_formatted' => Util::formatNi($this->fields['supplier_ni']),
            'lifecycle_label' => Lifecycle::label($this->fields['lifecycle']),
            'lifecycle_class' => Lifecycle::badgeClass($this->fields['lifecycle']),
            'candidates'  => self::canUpdate() && \Contract::canUpdate() ? (new Exporter())->findExisting($this->fields) : [],
            'can_update'  => self::canUpdate(),
            'can_export'  => self::canUpdate() && \Contract::canCreate(),
        ]);

        return true;
    }

    /** Re-read this contract, its amendments and commitments from the portal now. */
    public function refreshFromPortal(int $budget): void
    {
        $api = Config::api($budget);
        $raw = $api->contract($this->fields['cnpj'], (int) $this->fields['year'], (int) $this->fields['sequential']);
        if ($raw === null) {
            return;
        }
        $store = new Store((int) $this->fields['entities_id'], (int) $this->fields['is_recursive'], (int) $this->fields['plugin_pncp_watches_id']);
        $store->contract($raw);
        $this->getFromDB($this->getID());
        $store->amendments($this->getID(), $api->contractAmendments($this->fields['cnpj'], (int) $this->fields['year'], (int) $this->fields['sequential']), $this->fields['date_update']);
        $store->commitments($this->getID(), $api->contractCommitments($this->fields['cnpj'], (int) $this->fields['year'], (int) $this->fields['sequential']), $this->fields['date_update']);
    }

    public function getSpecificMassiveActions($checkitem = null)
    {
        $actions = parent::getSpecificMassiveActions($checkitem);
        if (Session::haveRight(self::$rightname, UPDATE)) {
            $sep = MassiveAction::CLASS_ACTION_SEPARATOR;
            if (\Contract::canCreate()) {
                $actions[self::class . $sep . 'export_contract'] = '<i class="ti ti-file-export"></i>' . __('Copy to GLPI contracts (with supplier)', 'pncp');
            }
            if (\Supplier::canCreate()) {
                $actions[self::class . $sep . 'export_supplier'] = '<i class="ti ti-truck"></i>' . __('Copy supplier to GLPI', 'pncp');
            }
        }

        return $actions;
    }

    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids)
    {
        $action = $ma->getAction();
        if (!in_array($action, ['export_contract', 'export_supplier'], true)) {
            parent::processMassiveActionsForOneItemtype($ma, $item, $ids);

            return;
        }
        $exporter = new Exporter();
        foreach ($ids as $id) {
            try {
                if (!$item->getFromDB($id)) {
                    throw new \RuntimeException('not found');
                }
                $ok = $action === 'export_contract'
                    ? $exporter->contract((int) $id) > 0
                    : $exporter->supplier($item->fields['supplier_ni'], $item->fields['supplier_name'], (int) $item->fields['entities_id']) > 0;
            } catch (Throwable $e) {
                $ok = false;
                $ma->addMessage($e->getMessage());
            }
            $ma->itemDone($item->getType(), $id, $ok ? MassiveAction::ACTION_OK : MassiveAction::ACTION_KO);
        }
        foreach ($exporter->lastErrors as $error) {
            $ma->addMessage($error);
        }
    }

    public function cleanDBonPurge()
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete('glpi_plugin_pncp_amendments', ['plugin_pncp_contracts_id' => $this->getID()]);
        $DB->delete('glpi_plugin_pncp_commitments', ['plugin_pncp_contracts_id' => $this->getID()]);
    }
}
