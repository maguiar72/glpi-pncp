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

use GlpiPlugin\Pncp\Install;
use GlpiPlugin\Pncp\Menu;
use GlpiPlugin\Pncp\SearchPage;

Session::checkRight(Install::RIGHT, READ);

if (isset($_POST['import'])) {
    Session::checkRight(Install::RIGHT, UPDATE);
    $keys = array_values(array_filter((array) ($_POST['selected'] ?? []), 'is_string'));
    if ($keys === []) {
        Session::addMessageAfterRedirect(__s('Select at least one record.', 'pncp'), false, WARNING);
    } else {
        $result = SearchPage::import($keys);
        Session::addMessageAfterRedirect(htmlescape(sprintf(__('%d record(s) imported.', 'pncp'), $result['ok'])));
        if ($result['partial']) {
            Session::addMessageAfterRedirect(__s('PNCP was slow: some records were not imported. Select them again.', 'pncp'), false, WARNING);
        }
        foreach ($result['errors'] as $error) {
            Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
        }
    }
    Html::back();
}

Menu::header(__('Search PNCP', 'pncp'), null);
SearchPage::show($_GET);
Html::footer();
