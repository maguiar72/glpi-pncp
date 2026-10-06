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

use GlpiPlugin\Pncp\Api\PncpException;
use GlpiPlugin\Pncp\Config;
use GlpiPlugin\Pncp\Exporter;
use GlpiPlugin\Pncp\Procurement;

$item = new Procurement();

if (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
    $item->redirectToList();
} elseif (isset($_POST['refresh'])) {
    $item->check((int) $_POST['id'], UPDATE);
    try {
        $n = $item->refreshFromPortal((int) Config::get('web_budget'));
        $msg = [sprintf(__('Procurement refreshed from PNCP (%d items).', 'pncp'), $n), INFO];
    } catch (PncpException $e) {
        $msg = [sprintf(__('PNCP error: %s', 'pncp'), $e->getMessage()), ERROR];
    }
    Session::addMessageAfterRedirect(htmlescape($msg[0]), false, $msg[1]);
    Html::back();
} elseif (isset($_POST['export_supplier'])) {
    $item->check((int) $_POST['id'], UPDATE);
    Session::checkRight(Supplier::$rightname, CREATE);
    $exporter = new Exporter();
    $id = $exporter->supplier($_POST['ni'] ?? '', $_POST['supplier_name'] ?? '', (int) $item->fields['entities_id']);
    if ($id > 0) {
        Session::addMessageAfterRedirect(sprintf(__('Supplier copied to GLPI: %s', 'pncp'), '<a href="' . htmlescape(Supplier::getFormURLWithID($id)) . '">' . htmlescape($_POST['supplier_name'] ?? '') . '</a>'));
    }
    foreach ($exporter->lastErrors as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::back();
}

Procurement::displayFullPage((int) ($_GET['id'] ?? 0));
