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

use GlpiPlugin\Pncp\Menu;
use GlpiPlugin\Pncp\Config;
use GlpiPlugin\Pncp\Sync\Syncer;
use GlpiPlugin\Pncp\Watch;

$watch = new Watch();

if (isset($_POST['add'])) {
    $watch->check(-1, CREATE, $_POST);
    $id = $watch->add($_POST);
    Html::redirect($id ? $watch->getLinkURL() : Watch::getFormURL());
} elseif (isset($_POST['update'])) {
    $watch->check((int) $_POST['id'], UPDATE);
    $watch->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $watch->check((int) $_POST['id'], PURGE);
    $watch->delete($_POST, true);
    $watch->redirectToList();
} elseif (isset($_POST['synchronize'])) {
    $watch->check((int) $_POST['id'], UPDATE);
    $result = $watch->synchronize((int) Config::get('web_budget'));
    $message = match ($result['status']) {
        Syncer::STATUS_OK      => sprintf(__('Synchronization complete: %d records.', 'pncp'), $result['records']),
        Syncer::STATUS_PARTIAL => sprintf(__('%d records synchronized. The rest continues in the background (automatic action).', 'pncp'), $result['records']),
        Syncer::STATUS_BUSY    => $result['message'],
        default                => sprintf(__('Synchronization failed: %s', 'pncp'), $result['message']),
    };
    Session::addMessageAfterRedirect(htmlescape($message), false, $result['status'] === Syncer::STATUS_ERROR ? ERROR : INFO);
    Html::back();
}

Session::checkRight(Watch::$rightname, READ);
Menu::header(Watch::getTypeName(1), Watch::class);
$options = ['id' => (int) ($_GET['id'] ?? 0)];
if ($options['id'] === 0) {
    // Prefill from the search page ("Monitor this organization/unit"); forwarded to the form tab.
    foreach (['cnpj', 'unit_code', 'name'] as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key] !== '') {
            $options[$key] = $_GET[$key];
        }
    }
}
$watch->display($options);
Html::footer();
