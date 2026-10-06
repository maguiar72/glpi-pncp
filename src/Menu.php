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
use Html;
use Session;

/**
 * The single sidebar entry "Management > PNCP" (the search page). Its options
 * only feed the breadcrumb; navigation between the plugin screens is the
 * shortcut bar rendered at the top of every page by showNav().
 */
class Menu extends CommonGLPI
{
    public static $rightname = Install::RIGHT;

    /** Screens of the shortcut bar, in order: menu option key => class (null = search page). */
    private const SCREENS = [
        'search'      => null,
        'watch'       => Watch::class,
        'procurement' => Procurement::class,
        'contract'    => Contract::class,
        'pricerecord' => PriceRecord::class,
        'pcaitem'     => PcaItem::class,
    ];

    public static function getMenuName()
    {
        return 'PNCP';
    }

    public static function getIcon()
    {
        return 'ti ti-building-bank';
    }

    public static function getSearchPageURL(): string
    {
        return self::url('/front/search.php');
    }

    /** Absolute path of a plugin resource (Plugin::getWebDir() is deprecated in GLPI 11). */
    public static function url(string $path): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/pncp' . $path;
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(Install::RIGHT, READ)) {
            return false;
        }

        // Menu paths are relative: GLPI prepends root_doc itself.
        $search = '/plugins/pncp/front/search.php';
        $menu = [
            'title' => self::getMenuName(),
            'page'  => $search,
            'icon'  => self::getIcon(),
        ];
        foreach (self::SCREENS as $key => $class) {
            $menu['options'][$key] = $class === null
                ? ['title' => __('Search'), 'page' => $search, 'icon' => 'ti ti-search']
                : [
                    'title' => $class::getTypeName(Session::getPluralNumber()),
                    'page'  => $class::getSearchURL(false),
                    'icon'  => $class::getIcon(),
                    'links' => ['search' => $class::getSearchURL(false)]
                        + ($class === Watch::class && Watch::canCreate() ? ['add' => Watch::getFormURL(false)] : []),
                ];
        }

        return $menu;
    }

    /** Menu option key of a screen class (for Html::header()). */
    public static function key(?string $class): string
    {
        return (string) array_search($class, self::SCREENS, true);
    }

    /** Page header with the breadcrumb "Management > PNCP > screen" and the shortcut bar. */
    public static function header(string $title, ?string $class): void
    {
        Html::header($title, '', 'management', self::class, self::key($class));
        self::showNav($class);
    }

    /** Shortcut bar: one card per screen, with the number of records visible to the user. */
    public static function showNav(?string $current): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $cards = [];
        foreach (self::SCREENS as $class) {
            if ($class === null) {
                $cards[] = ['label' => __('Search'), 'icon' => 'ti ti-search', 'url' => self::getSearchPageURL(), 'count' => null, 'active' => $current === null];
                continue;
            }
            $table = $class::getTable();
            $cards[] = [
                'label'  => $class::getTypeName(Session::getPluralNumber()),
                'icon'   => $class::getIcon(),
                'url'    => $class::getSearchURL(),
                'count'  => (int) $DB->request([
                    'COUNT' => 'c',
                    'FROM'  => $table,
                    'WHERE' => getEntitiesRestrictCriteria($table, '', '', true),
                ])->current()['c'],
                'active' => $current === $class,
            ];
        }
        TemplateRenderer::getInstance()->display('@pncp/nav.html.twig', ['cards' => $cards]);
    }
}
