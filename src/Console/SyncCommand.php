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
use GlpiPlugin\Pncp\Watch;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** php bin/console plugins:pncp:sync [id] [--budget=600] */
class SyncCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:pncp:sync')
            ->setDescription('Synchronize PNCP monitorings now (all active ones, or the given id)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Monitoring id')
            ->addOption('budget', 'b', InputOption::VALUE_REQUIRED, 'Time budget in seconds', '600')
            ->addOption('until-done', null, InputOption::VALUE_NONE, 'Repeat until every monitoring is fully synchronized');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['is_active' => 1];
        if ($input->getArgument('id')) {
            $where = ['id' => (int) $input->getArgument('id')];
        }
        $budget = max(30, (int) $input->getOption('budget'));
        $ids = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => Watch::getTable(), 'WHERE' => $where])), 'id');
        if ($ids === []) {
            $output->writeln('<comment>No monitoring found.</comment>');

            return 0;
        }

        $exit = 0;
        foreach ($ids as $id) {
            do {
                $watch = new Watch();
                $watch->getFromDB((int) $id);
                $started = microtime(true);
                $output->writeln(sprintf('<info>#%d %s</info> (%s %s)…', $id, $watch->fields['name'], $watch->fields['cnpj'], $watch->fields['unit_code']));
                $r = $watch->synchronize($budget);
                $output->writeln(sprintf(
                    '  %s — %d records in %ds%s',
                    $r['status'],
                    $r['records'],
                    (int) (microtime(true) - $started),
                    $r['message'] ? "\n  " . str_replace("\n", "\n  ", $r['message']) : '',
                ));
                if ($r['status'] === 'error') {
                    $exit = 1;
                }
            } while ($input->getOption('until-done') && $r['status'] === 'partial');
        }

        return $exit;
    }
}
