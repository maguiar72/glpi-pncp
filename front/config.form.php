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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pncp\Config;
use GlpiPlugin\Pncp\Menu;

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    Config::set($_POST);
    Session::addMessageAfterRedirect(__s('Settings saved.', 'pncp'));
    Html::back();
}

Html::header('PNCP', '', 'config', 'plugin');
TemplateRenderer::getInstance()->display('@pncp/config.html.twig', [
    'values'   => array_combine(array_keys(Config::DEFAULTS), array_map(Config::get(...), array_keys(Config::DEFAULTS))),
    'action'   => Menu::url('/front/config.form.php'),
    'cron_url' => CronTask::getSearchURL(),
]);
Html::footer();
