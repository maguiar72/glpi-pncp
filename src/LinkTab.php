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

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/** "PNCP" tab on GLPI contracts and suppliers: where the data came from. */
class LinkTab extends CommonGLPI
{
    public static $rightname = Install::RIGHT;

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!Session::haveRight(Install::RIGHT, READ) || !in_array($item::class, [\Contract::class, \Supplier::class], true)) {
            return '';
        }
        $count = count(self::linkedContracts($item));

        return $count > 0 || $item instanceof \Supplier ? self::createTabEntry('PNCP', $count, null, Menu::getIcon()) : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        /** @var \DBmysql $DB */
        global $DB;

        $contracts = self::linkedContracts($item);
        foreach ($contracts as &$c) {
            $c['url'] = Contract::getFormURLWithID($c['id']);
            $c['value'] = Record::money($c['global_value']);
        }
        $vars = ['contracts' => $contracts, 'is_supplier' => $item instanceof \Supplier, 'ni' => null, 'items' => []];
        if ($item instanceof \Supplier) {
            $link = $DB->request(['FROM' => 'glpi_plugin_pncp_supplierlinks', 'WHERE' => ['suppliers_id' => $item->getID()]])->current();
            if ($link) {
                $vars['ni'] = Util::formatNi($link['ni']);
                // Items this supplier won.
                $vars['items'] = iterator_to_array($DB->request([
                    'SELECT'    => ['i.item_number', 'i.name', 'i.awarded_quantity', 'i.awarded_unit_value', 'i.awarded_total_value', 'p.id AS procurement_id', 'p.name AS procurement_name'],
                    'FROM'      => 'glpi_plugin_pncp_procurementitems AS i',
                    'INNER JOIN' => ['glpi_plugin_pncp_procurements AS p' => ['ON' => ['i' => 'plugin_pncp_procurements_id', 'p' => 'id']]],
                    'WHERE'     => ['i.supplier_ni' => $link['ni']],
                    'ORDER'     => 'p.date_update DESC',
                    'LIMIT'     => 200,
                ]), false);
                foreach ($vars['items'] as &$i) {
                    $i['url'] = Procurement::getFormURLWithID($i['procurement_id']);
                    $i['unit'] = Record::money($i['awarded_unit_value']);
                    $i['total'] = Record::money($i['awarded_total_value']);
                }
            }
        }
        TemplateRenderer::getInstance()->display('@pncp/linktab.html.twig', $vars);

        return true;
    }

    private static function linkedContracts(CommonGLPI $item): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $field = $item instanceof \Contract ? 'contracts_id' : 'suppliers_id';

        return iterator_to_array($DB->request([
            'SELECT' => ['id', 'name', 'control_number', 'global_value', 'begin_date', 'end_date', 'date_export'],
            'FROM'   => Contract::getTable(),
            'WHERE'  => [$field => $item->getID()],
            'ORDER'  => 'end_date DESC',
        ]), false);
    }
}
