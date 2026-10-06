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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Pncp\Contract;
use GlpiPlugin\Pncp\Documents;
use GlpiPlugin\Pncp\LinkTab;
use GlpiPlugin\Pncp\Menu;
use GlpiPlugin\Pncp\PriceRecord;
use GlpiPlugin\Pncp\Procurement;
use GlpiPlugin\Pncp\Profile;

define('PLUGIN_PNCP_VERSION', '0.3.0');
define('PLUGIN_PNCP_MIN_GLPI', '11.0.0');
define('PLUGIN_PNCP_MAX_GLPI', '11.0.99');

function plugin_init_pncp()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['pncp'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('pncp')) {
        return;
    }

    Plugin::registerClass(Profile::class, ['addtabon' => \Profile::class]);
    Plugin::registerClass(LinkTab::class, ['addtabon' => [\Contract::class, \Supplier::class]]);
    // PNCP records can hold GLPI documents (files imported from the portal).
    foreach ([Contract::class, Procurement::class, PriceRecord::class] as $class) {
        Plugin::registerClass($class, ['document_types' => true]);
    }
    // "Search documents on PNCP" panel under the Documents tab.
    $PLUGIN_HOOKS[Hooks::POST_SHOW_TAB]['pncp'] = [Documents::class, 'postShowTab'];

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['pncp'] = 'css/pncp.css';
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['pncp'] = 'front/config.form.php';

    if (Session::haveRight(Contract::$rightname, READ)) {
        // A single sidebar entry; the screens are reached from the shortcut bar
        // shown at the top of every plugin page (see Menu::showNav()).
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['pncp'] = ['management' => Menu::class];
    }
}

function plugin_version_pncp()
{
    return [
        'name'         => 'PNCP',
        'version'      => PLUGIN_PNCP_VERSION,
        'author'       => 'Marcos Aguiar',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/maguiar72/glpi-pncp',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PNCP_MIN_GLPI,
                'max' => PLUGIN_PNCP_MAX_GLPI,
            ],
            'php'  => [
                'min'  => '8.2',
                'exts' => ['curl' => ['required' => true], 'json' => ['required' => true]],
            ],
        ],
    ];
}

function plugin_pncp_check_prerequisites()
{
    return true;
}

function plugin_pncp_check_config($verbose = false)
{
    return true;
}

/**
 * Search list display (Hooks::AUTO_GIVE_ITEM): monetary columns as "R$ 1.234,56".
 * The option keeps the "decimal" datatype, so filters (">1000000") and sorting still work on the number.
 *
 * @param string $type  item type of the list
 * @param int    $id    search option id
 * @param array  $data  result row
 * @param string $num   "<itemtype>_<id>" key of the column in $data
 */
function plugin_pncp_giveItem($type, $id, $data, $num)
{
    if (!is_a($type, \GlpiPlugin\Pncp\Record::class, true)) {
        return '';
    }
    $option = Glpi\Search\SearchOption::getOptionsForItemtype($type)[$id] ?? [];
    if (!in_array($option['field'] ?? '', \GlpiPlugin\Pncp\Record::MONEY_FIELDS, true)) {
        return '';
    }
    $raw = $data[$num][0]['name'] ?? null;
    if ($raw === null || $raw === '') {
        return '';
    }

    return "<span class='text-nowrap'>" . htmlescape(\GlpiPlugin\Pncp\Record::money($raw)) . '</span>';
}
