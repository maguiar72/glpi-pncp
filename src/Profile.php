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
use Html;
use Profile as GlpiProfile;
use Session;

/** "PNCP" tab on profiles: one right with READ / UPDATE / CREATE / PURGE. */
class Profile extends CommonGLPI
{
    public static $rightname = 'profile';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && $item->getField('interface') === 'central') {
            return self::createTabEntry('PNCP', 0, null, Menu::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof GlpiProfile) {
            return false;
        }
        self::showRightsForm($item);

        return true;
    }

    private static function showRightsForm(GlpiProfile $profile): void
    {
        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        echo '<div class="spaced">';
        if ($canedit) {
            echo '<form method="post" action="' . htmlescape(GlpiProfile::getFormURL()) . '">';
        }
        $profile->displayRightsChoiceMatrix([[
            'itemtype' => Watch::class,
            'label'    => __('PNCP: search, monitorings and imported data', 'pncp'),
            'field'    => Install::RIGHT,
            'rights'   => [
                READ   => __('Read'),
                UPDATE => __('Synchronize / import / copy to GLPI', 'pncp'),
                CREATE => __('Create monitorings', 'pncp'),
                PURGE  => __('Delete permanently'),
            ],
        ]], ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'PNCP']);
        if ($canedit) {
            echo '<div class="center">';
            echo Html::hidden('id', ['value' => $profile->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }
}
