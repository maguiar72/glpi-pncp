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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** php bin/console plugins:pncp:watch:add --cnpj=... [--unit=...] [--from=YYYY-MM-DD] [--name=...] */
class WatchAddCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:pncp:watch:add')
            ->setDescription('Create a PNCP monitoring')
            ->addOption('cnpj', null, InputOption::VALUE_REQUIRED, 'Organization CNPJ')
            ->addOption('unit', null, InputOption::VALUE_REQUIRED, 'Unit code (optional)', '')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Collect from (YYYY-MM-DD)', date('Y') . '-01-01')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name', '')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Entity id', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $watch = new Watch();
        $id = $watch->add([
            'name'        => $input->getOption('name') ?: null,
            'cnpj'        => (string) $input->getOption('cnpj'),
            'unit_code'   => (string) $input->getOption('unit'),
            'date_begin'  => (string) $input->getOption('from'),
            'entities_id' => (int) $input->getOption('entity'),
            'is_active'   => 1,
        ]);
        if (!$id) {
            $output->writeln('<error>Could not create the monitoring (invalid CNPJ?).</error>');

            return 1;
        }
        $output->writeln("<info>Monitoring #$id created.</info>");

        return 0;
    }
}
