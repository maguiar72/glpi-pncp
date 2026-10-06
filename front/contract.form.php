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
use GlpiPlugin\Pncp\Contract;
use GlpiPlugin\Pncp\Exporter;

$item = new Contract();

if (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
    $item->redirectToList();
} elseif (isset($_POST['refresh'])) {
    $item->check((int) $_POST['id'], UPDATE);
    try {
        $item->refreshFromPortal((int) Config::get('web_budget'));
        $msg = [__('Contract refreshed from PNCP.', 'pncp'), INFO];
    } catch (PncpException $e) {
        $msg = [sprintf(__('PNCP error: %s', 'pncp'), $e->getMessage()), ERROR];
    }
    Session::addMessageAfterRedirect(htmlescape($msg[0]), false, $msg[1]);
    Html::back();
} elseif (isset($_POST['link_contract'])) {
    $item->check((int) $_POST['id'], UPDATE);
    Session::checkRight(\Contract::$rightname, UPDATE);
    $exporter = new Exporter();
    $id = $exporter->link($item->getID(), (int) ($_POST['glpi_id'] ?? 0));
    if ($id > 0) {
        Session::addMessageAfterRedirect(sprintf(
            __('Linked to the existing GLPI contract: %s. Fields already filled in were kept; empty ones were completed.', 'pncp'),
            '<a href="' . htmlescape(\Contract::getFormURLWithID($id)) . '">' . htmlescape($item->fields['name']) . '</a>',
        ));
    }
    foreach ($exporter->lastErrors as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::back();
} elseif (isset($_POST['export_contract']) || isset($_POST['export_supplier'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $exporter = new Exporter();
    if (isset($_POST['export_contract'])) {
        Session::checkRight(\Contract::$rightname, CREATE);
        $id = $exporter->contract($item->getID());
        if ($id > 0) {
            Session::addMessageAfterRedirect(sprintf(
                in_array($id, $exporter->adopted, true)
                    ? __('A matching GLPI contract already existed and was linked: %s. Fields already filled in were kept.', 'pncp')
                    : __('Copied to GLPI contracts: %s', 'pncp'),
                '<a href="' . htmlescape(\Contract::getFormURLWithID($id)) . '">' . htmlescape($item->fields['name']) . '</a>',
            ));
        }
    } else {
        Session::checkRight(Supplier::$rightname, CREATE);
        $id = $exporter->supplier($item->fields['supplier_ni'], $item->fields['supplier_name'], (int) $item->fields['entities_id']);
        if ($id > 0) {
            global $DB;
            $DB->update(Contract::getTable(), ['suppliers_id' => $id], ['id' => $item->getID()]);
            Session::addMessageAfterRedirect(sprintf(
                __('Supplier copied to GLPI: %s', 'pncp'),
                '<a href="' . htmlescape(Supplier::getFormURLWithID($id)) . '">' . htmlescape($item->fields['supplier_name']) . '</a>',
            ));
        }
    }
    foreach ($exporter->lastErrors as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::back();
}

Contract::displayFullPage((int) ($_GET['id'] ?? 0));
