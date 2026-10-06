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
use Glpi\Application\View\TemplateRenderer;
use Html;
use Session;

/**
 * Base class of the records imported from the portal (read-only in GLPI:
 * they are refreshed by synchronization; they can only be purged).
 */
abstract class Record extends CommonDBTM
{
    public static $rightname = Install::RIGHT;

    public $dohistory = false;

    /** Portal page kind ("editais", "contratos", "atas") used to build links; null when there is none. */
    protected const PORTAL_KIND = null;

    /** Records only come from the portal. UPDATE is the right to refresh/copy them, not to edit fields. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getIcon()
    {
        return 'ti ti-building-bank';
    }


    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        if (in_array(static::class, Documents::ITEMTYPES, true)) {
            $this->addStandardTab(\Document_Item::class, $tabs, $options);
        }
        $this->addStandardTab(static::class, $tabs, $options);

        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof self && $item->getID() > 0) {
            return self::createTabEntry(__('Raw data', 'pncp'), 0, null, 'ti ti-code');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            $raw = json_decode((string) ($item->fields['raw'] ?? ''), true);
            TemplateRenderer::getInstance()->display('@pncp/raw.html.twig', [
                'json' => json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        return true;
    }

    /** Public portal page of this record. */
    public function portalUrl(): ?string
    {
        if (static::PORTAL_KIND === null || empty($this->fields['cnpj']) || empty($this->fields['sequential'])) {
            return null;
        }

        return Util::portalUrl(static::PORTAL_KIND, $this->fields['cnpj'], (int) $this->fields['year'], (int) $this->fields['sequential']);
    }

    /** Shared search options: name, id, organization, unit, year, watch, entity, dates. */
    protected function baseSearchOptions(): array
    {
        $table = static::getTable();
        $tab = [
            ['id' => 'common', 'name' => static::getTypeName(1)],
            ['id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $table, 'field' => 'control_number', 'name' => __('PNCP control number', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 4, 'table' => $table, 'field' => 'cnpj', 'name' => __('Organization CNPJ', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 5, 'table' => $table, 'field' => 'org_name', 'name' => __('Organization', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 6, 'table' => $table, 'field' => 'unit_code', 'name' => __('Unit code', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 7, 'table' => $table, 'field' => 'unit_name', 'name' => __('Unit', 'pncp'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 8, 'table' => $table, 'field' => 'year', 'name' => __('Year', 'pncp'), 'datatype' => 'number', 'min' => 2021, 'max' => 2100, 'massiveaction' => false],
            ['id' => 9, 'table' => $table, 'field' => 'object', 'name' => __('Object', 'pncp'), 'datatype' => 'text', 'massiveaction' => false],
            ['id' => 10, 'table' => Watch::getTable(), 'field' => 'name', 'name' => Watch::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 11, 'table' => $table, 'field' => 'date_update', 'name' => __('Updated on PNCP', 'pncp'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 12, 'table' => $table, 'field' => 'date_sync', 'name' => __('Last synchronization', 'pncp'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => \Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false],
        ];

        return $tab;
    }

    protected function renderDetail(string $template, array $vars = []): void
    {
        TemplateRenderer::getInstance()->display("@pncp/$template", $vars + [
            'item'       => $this,
            'f'          => $this->fields,
            'portal_url' => $this->portalUrl(),
            'can_purge'  => static::canPurge(),
        ]);
    }

    /** Search columns shown as currency ("R$ 1.234,56") by plugin_pncp_giveItem(); they stay plain decimals for filtering/sorting. */
    public const MONEY_FIELDS = ['estimated_value', 'awarded_value', 'global_value', 'unit_value', 'total_value'];

    /** "R$ 1.234,56" for templates. */
    public static function money(mixed $value): string
    {
        return Util::money($value === null ? null : (float) $value);
    }

    /** Date for templates, in the GLPI display format. */
    public static function date(mixed $value): string
    {
        return Util::showDate($value);
    }

    /** Header + body + footer of a front "form" page. */
    public static function displayFullPage(int $id): void
    {
        Session::checkRight(static::$rightname, READ);
        Menu::header(static::getTypeName(1), static::class);
        (new static())->display(['id' => $id]);
        Html::footer();
    }
}
