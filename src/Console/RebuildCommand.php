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

namespace GlpiPlugin\Pncp\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Pncp\Lifecycle;
use GlpiPlugin\Pncp\Store;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-derive every column of the imported records from their stored raw JSON
 * (titles, projections, lifecycle), without calling the portal. Run it after an
 * upgrade that changes how records are projected.
 *
 * php bin/console plugins:pncp:rebuild
 */
class RebuildCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:pncp:rebuild')
            ->setDescription('Rebuild titles, columns and lifecycle of imported PNCP records from their raw JSON (no network)');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \DBmysql $DB */
        global $DB;

        $store = new Store();
        foreach (['procurements' => 'procurement', 'contracts' => 'contract', 'pricerecords' => 'priceRecord'] as $table => $method) {
            $n = 0;
            foreach ($DB->request(['SELECT' => ['raw'], 'FROM' => "glpi_plugin_pncp_$table"]) as $row) {
                $raw = json_decode((string) $row['raw'], true);
                if (is_array($raw) && $store->$method($raw)) {
                    $n++;
                }
            }
            $output->writeln(sprintf('%s: %d record(s) rebuilt', $table, $n));
        }
        $output->writeln(sprintf('lifecycle: %d contract(s) changed', Lifecycle::refresh()));

        return 0;
    }
}
