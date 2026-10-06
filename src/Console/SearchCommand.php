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
use GlpiPlugin\Pncp\Config;
use GlpiPlugin\Pncp\SearchPage;
use GlpiPlugin\Pncp\Util;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** php bin/console plugins:pncp:search "text" [--cnpj=] [--unit=] [--year=] [--type=edital|contrato|ata] */
class SearchCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:pncp:search')
            ->setDescription('Search the PNCP portal (diagnostics; nothing is stored except the unit catalogue)')
            ->addArgument('text', InputArgument::OPTIONAL, 'Text', '')
            ->addOption('cnpj', null, InputOption::VALUE_REQUIRED, 'Organization CNPJ', '')
            ->addOption('unit', null, InputOption::VALUE_REQUIRED, 'Unit code', '')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Year', '')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'edital|contrato|ata', 'edital');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $api = Config::api(120);
        $query = [
            'q'    => (string) $input->getArgument('text'),
            'type' => (string) $input->getOption('type'),
            'year' => (int) $input->getOption('year'),
            'size' => 10,
        ];
        $cnpj = Util::ni((string) $input->getOption('cnpj'));
        if ($cnpj !== '') {
            $units = SearchPage::units($api, $cnpj);
            $output->writeln(sprintf('%d unit(s) for %s', count($units), Util::formatNi($cnpj)));
            if ($units === []) {
                return 1;
            }
            $query['org_pncp_id'] = (int) $units[0]['org_pncp_id'];
            $unit = Util::unitCode((string) $input->getOption('unit'));
            foreach ($units as $u) {
                if ($unit !== '' && $u['code'] === $unit) {
                    $query['unit_pncp_ids'] = $u['pncp_ids'];
                    $output->writeln("Unit: {$u['pncp_code']} {$u['name']} (pncp ids " . implode('|', $u['pncp_ids']) . ')');
                }
            }
        }
        $result = $api->search($query);
        $output->writeln(sprintf('<info>%d result(s)</info> — %d request(s), %d retry(ies)', $result['total'], $api->client->requests, $api->client->retries));
        foreach ($result['items'] as $i) {
            $output->writeln(sprintf(
                '- %s | %s/%s | %s | %s',
                $i['numero_controle_pncp'] ?? '?',
                $i['orgao_cnpj'] ?? '',
                $i['unidade_codigo'] ?? '',
                $i['title'] ?? '',
                Util::truncate($i['description'] ?? '', 80),
            ));
        }

        return 0;
    }
}
