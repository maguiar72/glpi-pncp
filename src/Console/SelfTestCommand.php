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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Console\AbstractCommand;
use GlpiPlugin\Pncp\Config;
use GlpiPlugin\Pncp\Contract;
use GlpiPlugin\Pncp\Exporter;
use GlpiPlugin\Pncp\LinkTab;
use GlpiPlugin\Pncp\Menu;
use GlpiPlugin\Pncp\PcaItem;
use GlpiPlugin\Pncp\PriceRecord;
use GlpiPlugin\Pncp\Procurement;
use GlpiPlugin\Pncp\Profile;
use GlpiPlugin\Pncp\SearchPage;
use GlpiPlugin\Pncp\Watch;
use Search;
use Session;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Renders every screen of the plugin with the data in the database and runs a
 * GLPI export inside a rolled-back transaction. Nothing is persisted.
 *
 * php bin/console plugins:pncp:selftest --user=glpi [--language=pt_BR] [--network [--cnpj=14 digits [--unit=code]]]
 */
class SelfTestCommand extends AbstractCommand
{
    private int $failures = 0;

    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:pncp:selftest')
            ->setDescription('Render all plugin screens and dry-run a GLPI export (nothing is persisted)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Super-admin login', 'glpi')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Language', 'pt_BR')
            ->addOption('network', null, InputOption::VALUE_NONE, 'Also run a live search on the portal')
            ->addOption('cnpj', null, InputOption::VALUE_REQUIRED, 'With --network: organization CNPJ for the live search by CNPJ', '')
            ->addOption('unit', null, InputOption::VALUE_REQUIRED, 'With --network: unit code (optional)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \DBmysql $DB */
        global $DB;

        $this->loadUserSession((string) $input->getOption('user'));
        Session::changeProfile(4);
        $_SESSION['glpilanguage'] = (string) $input->getOption('language');
        Session::loadLanguage();
        $output->writeln('Language: ' . $_SESSION['glpilanguage'] . ' — "' . __('Search PNCP', 'pncp') . '"');

        $first = static fn(string $table) => (int) ($DB->request(['SELECT' => 'id', 'FROM' => $table, 'ORDER' => 'id', 'LIMIT' => 1])->current()['id'] ?? 0);

        foreach ([Watch::class, Procurement::class, Contract::class, PriceRecord::class, PcaItem::class] as $class) {
            $id = $first($class::getTable());
            if ($id > 0) {
                $this->check($output, "$class form #$id", function () use ($class, $id) {
                    $item = new $class();
                    $item->getFromDB($id);
                    $item->showForm($id);
                    foreach ($item->defineTabs() as $tab => $label) {
                        if (str_starts_with($tab, $class . '$')) {
                            \CommonGLPI::displayStandardTab($item, $tab);
                        }
                    }
                });
            } else {
                $output->writeln("<comment>skip</comment> $class form (no record)");
            }
            $this->check($output, "$class list", function () use ($class) {
                $_GET = [];
                Search::show($class);
            });
        }

        $this->check($output, 'Watch new form', fn() => (new Watch())->showForm(0));
        $this->check($output, 'Search page (empty)', fn() => SearchPage::show([]));
        $this->check($output, 'Search page (validation error)', fn() => SearchPage::show(['go' => 1, 'cnpj' => '123']));
        if ($input->getOption('network')) {
            if ((string) $input->getOption('cnpj') !== '') {
                $this->check($output, 'Search page (live, CNPJ + unit)', fn() => SearchPage::show(['go' => 1, 'q' => 'limpeza', 'cnpj' => (string) $input->getOption('cnpj'), 'unit' => (string) $input->getOption('unit')]));
            } else {
                $output->writeln('skip Search page (live, CNPJ + unit): pass --cnpj=<CNPJ> [--unit=<code>]');
            }
            $this->check($output, 'Search page (live, contracts by name)', fn() => SearchPage::show(['go' => 1, 'q' => 'limpeza', 'type' => 'contrato', 'year' => (int) date('Y') - 1]));
        }
        if ($input->getOption('network') && ($cid = $first(Contract::getTable())) > 0) {
            $this->check($output, "PNCP documents of contract #$cid (live)", function () use ($cid, $output) {
                $item = new Contract();
                $item->getFromDB($cid);
                $list = \GlpiPlugin\Pncp\Documents::listFiles($item);
                $output->writeln(sprintf('    %d file(s), %d error(s)', count($list['files']), count($list['errors'])));
                foreach (array_slice($list['files'], 0, 4) as $f) {
                    $output->writeln("    - {$f['type']}: {$f['title']} ({$f['source']})");
                }
                \GlpiPlugin\Pncp\Documents::showPage($item);

                // Import the smallest-looking file inside a rolled-back transaction, then
                // remove the stored file (the filesystem is not transactional).
                $file = end($list['files']);
                if ($file) {
                    $DB = $GLOBALS['DB'];
                    $DB->beginTransaction();
                    $stored = null;
                    try {
                        $result = \GlpiPlugin\Pncp\Documents::import($item, [$file['key']]);
                        $doc = $DB->request(['SELECT' => ['documents_id'], 'FROM' => 'glpi_plugin_pncp_documents', 'WHERE' => ['source_key' => $file['key']]])->current();
                        $d = new \Document();
                        if (!$doc || !$d->getFromDB((int) $doc['documents_id'])) {
                            throw new \RuntimeException('document not created: ' . implode('; ', $result['errors']));
                        }
                        $stored = GLPI_DOC_DIR . '/' . $d->fields['filepath'];
                        $output->writeln(sprintf('    imported "%s" as %s (%d bytes, %s)', $file['title'], $d->fields['filename'], filesize($stored), $d->fields['mime']));
                    } finally {
                        $DB->rollBack();
                        if ($stored && is_file($stored) && countElementsInTable('glpi_documents', ['filepath' => $d->fields['filepath']]) === 0) {
                            unlink($stored);
                        }
                    }
                }
            });
        }
        $this->check($output, 'Config page', fn() => TemplateRenderer::getInstance()->display('@pncp/config.html.twig', [
            'values'   => array_combine(array_keys(Config::DEFAULTS), array_map(Config::get(...), array_keys(Config::DEFAULTS))),
            'action'   => Menu::url('/front/config.form.php'),
            'cron_url' => \CronTask::getSearchURL(),
        ]));
        $this->check($output, 'Profile tab', function () {
            $p = new \Profile();
            $p->getFromDB(4);
            Profile::displayTabContentForItem($p);
        });
        $this->check($output, 'Sidebar menu entries', function () use ($output) {
            // In CLI the plugin was initialized before the user session had rights; redo it.
            plugin_init_pncp();
            $menu = \Html::generateMenuSession(true);
            $entries = array_values(array_filter(
                $menu['management']['content'] ?? [],
                static fn($e) => ($e['title'] ?? '') === 'PNCP',
            ));
            if (count($entries) !== 1 || count($entries[0]['options'] ?? []) !== 6) {
                throw new \RuntimeException(sprintf('expected one "PNCP" entry with 6 screens, got %d entries', count($entries)));
            }
            $output->writeln('    PNCP -> ' . $entries[0]['page']);
            foreach ($entries[0]['options'] as $key => $o) {
                $output->writeln("      $key: {$o['title']} -> {$o['page']}");
            }
        });
        $this->check($output, 'Shortcut bar', fn() => Menu::showNav(Contract::class));

        $this->check($output, 'Object summaries', function () use ($output) {
            $samples = [
                ['Contrato (termo inicial)', '00032', 2025, 'SERVIÇO DE SUPORTE TÉCNICO MENSAL PARA O SOFTWARE PERGAMUM, COM ATUALIZAÇÃO E MANUTENÇÃO PREVENTIVA'],
                ['Outros', '00004', 2026, 'ESTABELECER UM REGIME DE COOPERAÇÃO MÚTUA ENTRE O IBGE E A ABC, COM VISTAS À RESERVA DE VAGAS'],
                ['Pregão - Eletrônico', '90006', 2026, 'Contratação de empresa para prestação de serviços continuados de limpeza, conservação e apoio administrativo'],
                ['ARP', '00008', 2025, 'Registro de Preços – SRP, para o fornecimento eventual de materiais de expediente'],
            ];
            foreach ($samples as [$kind, $num, $year, $object]) {
                $output->writeln('    ' . \GlpiPlugin\Pncp\Util::title($kind, $num, $year, $object));
            }
        });
        $this->check($output, 'Date and contract-number helpers', function () {
            $U = \GlpiPlugin\Pncp\Util::class;
            $cases = [
                [$U::normalizeNumber('00033/2026'), '33/2026'],
                [$U::normalizeNumber('33/26'), '33/2026'],
                [$U::normalizeNumber('Contrato nº 33-2026'), '33/2026'],
                [$U::normalizeNumber('sem numero'), ''],
                [$U::brDate('2031-10-29'), '29/10/2031'],
                [$U::showDate('2026-09-30') !== '' && $U::showDate('2026-09-30') !== '2026-09-30', true],
                [$U::showDate(null), ''],
            ];
            foreach ($cases as $i => [$got, $want]) {
                if ($got !== $want) {
                    throw new \RuntimeException("case $i: got " . var_export($got, true));
                }
            }
        });
        $this->check($output, 'Lifecycle rules', function () {
            $L = \GlpiPlugin\Pncp\Lifecycle::class;
            $c = ['begin_date' => '2026-01-01', 'end_date' => '2026-12-31'];
            $cases = [
                [$L::compute($c, [], '2026-06-01'), $L::ACTIVE],
                [$L::compute($c, [], '2027-01-05'), $L::EXPIRED],
                [$L::compute($c, [], '2025-12-01'), $L::PLANNED],
                [$L::compute($c, [['end_date' => '2027-12-31', 'type_name' => 'Termo Aditivo', 'object' => '', 'raw' => '{}']], '2027-01-05'), $L::EXTENDED],
                [$L::compute($c, [['end_date' => null, 'type_name' => 'Termo de Rescisão', 'object' => '', 'raw' => '{}']], '2026-06-01'), $L::INACTIVE],
            ];
            foreach ($cases as $i => [$got, $want]) {
                if ($got !== $want) {
                    throw new \RuntimeException("case $i: got $got, expected $want");
                }
            }
        });

        // Export dry-run: everything below is rolled back.
        $contractId = $first(Contract::getTable());
        if ($contractId > 0) {
            $DB->beginTransaction();
            try {
                $this->check($output, "Export PNCP contract #$contractId to GLPI (rolled back)", function () use ($contractId, $output) {
                    $exporter = new Exporter();
                    $glpiId = $exporter->contract($contractId);
                    if ($glpiId <= 0) {
                        throw new \RuntimeException(implode('; ', $exporter->lastErrors) ?: 'no contract created');
                    }
                    $c = new \Contract();
                    $c->getFromDB($glpiId);
                    $output->writeln(sprintf('    contract "%s" num=%s begin=%s duration=%s months type=%s', $c->fields['name'], $c->fields['num'], $c->fields['begin_date'], $c->fields['duration'], \Dropdown::getDropdownName('glpi_contracttypes', $c->fields['contracttypes_id'])));
                    $s = new \Supplier();
                    $pncp = new Contract();
                    $pncp->getFromDB($contractId);
                    $s->getFromDB($pncp->fields['suppliers_id']);
                    $output->writeln(sprintf('    supplier "%s" registration=%s', $s->fields['name'] ?? '-', $s->fields['registration_number'] ?? '-'));
                    $output->writeln('    state: ' . \Dropdown::getDropdownName('glpi_states', $c->fields['states_id']));
                    // Second export must update, not duplicate.
                    if ($exporter->contract($contractId) !== $glpiId) {
                        throw new \RuntimeException('second export created a duplicate');
                    }
                    // A name edited in GLPI is never overwritten again.
                    $c->update(['id' => $glpiId, 'name' => 'Edited by user']);
                    $exporter->contract($contractId);
                    $c->getFromDB($glpiId);
                    if ($c->fields['name'] !== 'Edited by user') {
                        throw new \RuntimeException('user-edited name was overwritten: ' . $c->fields['name']);
                    }
                    $output->writeln('    user-edited name kept on re-export');
                    LinkTab::displayTabContentForItem($c);
                    LinkTab::displayTabContentForItem($s);
                });
            } finally {
                $DB->rollBack();
            }
        }

        $output->writeln($this->failures === 0 ? '<info>All checks passed.</info>' : "<error>{$this->failures} check(s) failed.</error>");

        return $this->failures === 0 ? 0 : 1;
    }

    private function check(OutputInterface $output, string $label, callable $fn): void
    {
        ob_start();
        try {
            $fn();
            $html = (string) ob_get_clean();
            $output->writeln(sprintf('<info>ok</info>   %s (%d bytes)', $label, strlen($html)));
        } catch (Throwable $e) {
            ob_end_clean();
            $this->failures++;
            $output->writeln(sprintf('<error>FAIL</error> %s: %s at %s:%d', $label, $e->getMessage(), basename($e->getFile()), $e->getLine()));
        }
    }
}
