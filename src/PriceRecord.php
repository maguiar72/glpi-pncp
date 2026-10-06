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

/** Ata de registro de preços (price registration record). */
class PriceRecord extends Record
{
    public static function getTypeName($nb = 0)
    {
        return _n('Price registration record', 'Price registration records', $nb, 'pncp');
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    /** Control number "CNPJ-1-SEQ/YEAR-ATA" → https://pncp.gov.br/app/atas/CNPJ/YEAR/SEQ/ATA */
    public function portalUrl(): ?string
    {
        if (!preg_match('/^(\w{14})-\d+-(\d+)\/(\d{4})-(\d+)$/', (string) $this->fields['control_number'], $m)) {
            return null;
        }

        return Util::portalUrl('atas', $m[1], (int) $m[3], (int) $m[2], (int) $m[4]);
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $tab = $this->baseSearchOptions();
        array_push(
            $tab,
            ['id' => 20, 'table' => $t, 'field' => 'number', 'name' => __('Number'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 21, 'table' => $t, 'field' => 'begin_date', 'name' => __('Start date'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 22, 'table' => $t, 'field' => 'end_date', 'name' => __('End date'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 23, 'table' => $t, 'field' => 'is_cancelled', 'name' => __('Cancelled', 'pncp'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => 24, 'table' => $t, 'field' => 'procurement_control_number', 'name' => __('Source procurement', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 25, 'table' => $t, 'field' => 'date_signature', 'name' => __('Signature date', 'pncp'), 'datatype' => 'date', 'massiveaction' => false],
        );

        return $tab;
    }

    public function showForm($ID, array $options = [])
    {
        /** @var \DBmysql $DB */
        global $DB;

        $this->initForm($ID, $options);
        $control = $this->fields['procurement_control_number'];
        $procurement = $control ? $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => Procurement::getTable(),
            'WHERE'  => ['control_number' => $control],
        ])->current() : null;
        // Suppliers of a price record are the winners of the items of its procurement.
        $winners = $procurement ? iterator_to_array($DB->request([
            'SELECT'   => ['supplier_ni', 'supplier_name'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_plugin_pncp_procurementitems',
            'WHERE'    => ['plugin_pncp_procurements_id' => $procurement['id'], 'NOT' => ['supplier_ni' => null]],
        ]), false) : [];
        foreach ($winners as &$w) {
            $w['ni_formatted'] = Util::formatNi($w['supplier_ni']);
        }
        $this->renderDetail('pricerecord.html.twig', ['procurement' => $procurement, 'winners' => $winners]);

        return true;
    }
}
