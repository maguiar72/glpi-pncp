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

/** Item of the annual procurement plan (Plano de Contratações Anual). */
class PcaItem extends Record
{
    public static function getTypeName($nb = 0)
    {
        return _n('Annual plan item (PCA)', 'Annual plan items (PCA)', $nb, 'pncp');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-dollar';
    }

    /**
     * Portal page of the plan the item belongs to. Plan id "CNPJ-0-SEQ/YEAR" → https://pncp.gov.br/app/pca/CNPJ/YEAR/SEQ
     * (same /app/<kind>/<cnpj>/<year>/<sequential> convention as procurements, contracts and records).
     */
    public function portalUrl(): ?string
    {
        return self::planUrl((string) ($this->fields['pca_id'] ?? ''));
    }

    public static function planUrl(string $planId): ?string
    {
        if (!preg_match('/^(\w{14})-\d+-(\d+)\/(\d{4})$/', $planId, $m)) {
            return null;
        }

        return Util::portalUrl('pca', $m[1], (int) $m[3], (int) $m[2]);
    }

    /** Plan of the most recent year in GLPI, for the shortcut bar. */
    public static function latestPlanUrl(): ?string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'SELECT' => ['pca_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['NOT' => ['pca_id' => null]] + getEntitiesRestrictCriteria(self::getTable(), '', '', true),
            'ORDER'  => ['year DESC', 'pca_id DESC'],
            'LIMIT'  => 1,
        ])->current();

        return $row ? self::planUrl((string) $row['pca_id']) : null;
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $tab = array_values(array_filter(
            $this->baseSearchOptions(),
            // PCA items have no control number, organization name nor object column.
            static fn($o) => !in_array($o['id'], [3, 5, 9], true),
        ));
        array_push(
            $tab,
            ['id' => 20, 'table' => $t, 'field' => 'item_number', 'name' => __('Item number', 'pncp'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 21, 'table' => $t, 'field' => 'category_name', 'name' => __('Category'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 22, 'table' => $t, 'field' => 'catalog_class', 'name' => __('Catalog class', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 23, 'table' => $t, 'field' => 'group_name', 'name' => __('Procurement group', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 24, 'table' => $t, 'field' => 'quantity', 'name' => __('Quantity'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => 25, 'table' => $t, 'field' => 'unit_value', 'name' => __('Unit value', 'pncp'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => 26, 'table' => $t, 'field' => 'total_value', 'name' => __('Total value', 'pncp'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => 27, 'table' => $t, 'field' => 'date_desired', 'name' => __('Desired date', 'pncp'), 'datatype' => 'date', 'massiveaction' => false],
        );

        return $tab;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->renderDetail('pcaitem.html.twig');

        return true;
    }
}
