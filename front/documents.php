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

use GlpiPlugin\Pncp\Documents;
use GlpiPlugin\Pncp\Install;
use GlpiPlugin\Pncp\Menu;

Session::checkRight(Install::RIGHT, READ);

$itemtype = (string) ($_REQUEST['itemtype'] ?? '');
if (!in_array($itemtype, Documents::ITEMTYPES, true)) {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}
$item = new $itemtype();
$item->check((int) ($_REQUEST['items_id'] ?? 0), READ);

if (isset($_POST['import'])) {
    Session::checkRight(Install::RIGHT, UPDATE);
    Session::checkRight(Document::$rightname, CREATE);
    $selected = array_values(array_filter((array) ($_POST['selected'] ?? []), 'is_string'));
    if ($selected === []) {
        Session::addMessageAfterRedirect(__s('Select at least one record.', 'pncp'), false, WARNING);
        Html::back();
    }
    $result = Documents::import($item, $selected);
    Session::addMessageAfterRedirect(htmlescape(sprintf(__('%d document(s) imported.', 'pncp'), $result['ok'])));
    if ($result['partial']) {
        Session::addMessageAfterRedirect(__s('PNCP was slow: some records were not imported. Select them again.', 'pncp'), false, WARNING);
    }
    foreach ($result['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    if ($item instanceof \GlpiPlugin\Pncp\Contract && $result['ok'] > 0) {
        $glpiId = (int) $item->fields['contracts_id'];
        Session::addMessageAfterRedirect($glpiId > 0
            ? sprintf(__s('Also linked to the GLPI contract: %s.', 'pncp'), '<a href="' . htmlescape(\Contract::getFormURLWithID($glpiId) . '&forcetab=Document_Item$1') . '">' . __s('Open GLPI contract', 'pncp') . '</a>')
            : __s('This contract was not copied to GLPI yet: the documents appear in the GLPI contract once you copy it.', 'pncp'), false, $glpiId > 0 ? INFO : WARNING);
    }
    Html::redirect($item->getLinkURL() . '&forcetab=Document_Item$1');
}

Menu::header(__('Documents published on PNCP', 'pncp'), $item instanceof \Contract ? \GlpiPlugin\Pncp\Contract::class : $itemtype);
Documents::showPage($item);
Html::footer();
