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

namespace GlpiPlugin\Pncp;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pncp\Api\Pncp;
use GlpiPlugin\Pncp\Api\PncpException;
use GlpiPlugin\Pncp\Sync\Syncer;
use Session;
use Throwable;

/**
 * Interactive search on the portal (full-text search API) and on-demand import
 * of the selected records.
 */
final class SearchPage
{
    private const TYPE_TABLE = [
        'edital'   => 'glpi_plugin_pncp_procurements',
        'contrato' => 'glpi_plugin_pncp_contracts',
        'ata'      => 'glpi_plugin_pncp_pricerecords',
    ];

    private const TYPE_CLASS = [
        'edital'   => Procurement::class,
        'contrato' => Contract::class,
        'ata'      => PriceRecord::class,
    ];

    /** @param array<string, mixed> $get */
    public static function show(array $get): void
    {
        $filters = [
            'q'     => trim((string) ($get['q'] ?? '')),
            'cnpj'  => Util::ni((string) ($get['cnpj'] ?? '')),
            'unit'  => trim((string) ($get['unit'] ?? '')),
            'year'  => (int) ($get['year'] ?? 0) ?: '',
            'type'  => in_array($get['type'] ?? '', Pncp::SEARCH_TYPES, true) ? $get['type'] : 'edital',
            'page'  => max(1, (int) ($get['page'] ?? 1)),
        ];
        $submitted = isset($get['go']);
        $result = ['items' => [], 'total' => 0];
        $error = null;
        $unitsOfCnpj = [];

        if ($submitted) {
            try {
                if ($filters['q'] === '' && $filters['cnpj'] === '') {
                    throw new \InvalidArgumentException(__('Inform a text (name, object...) or an organization CNPJ.', 'pncp'));
                }
                $api = Config::api((int) Config::get('web_budget'));
                $query = ['q' => $filters['q'], 'type' => $filters['type'], 'year' => $filters['year'], 'page' => $filters['page'], 'size' => 25];
                if ($filters['cnpj'] !== '') {
                    if (!Util::isValidCnpj($filters['cnpj'])) {
                        throw new \InvalidArgumentException(__('Invalid CNPJ', 'pncp'));
                    }
                    $unitsOfCnpj = self::units($api, $filters['cnpj']);
                    if ($unitsOfCnpj === []) {
                        throw new \InvalidArgumentException(__('This CNPJ has no units registered on PNCP.', 'pncp'));
                    }
                    $query['org_pncp_id'] = (int) $unitsOfCnpj[0]['org_pncp_id'];
                    if ($filters['unit'] !== '') {
                        $unit = array_values(array_filter($unitsOfCnpj, static fn($u) => $u['code'] === Util::unitCode($filters['unit'])))[0] ?? null;
                        if ($unit === null) {
                            throw new \InvalidArgumentException(sprintf(__('Unit %s not found for this CNPJ.', 'pncp'), $filters['unit']));
                        }
                        $query['unit_pncp_ids'] = $unit['pncp_ids'];
                    }
                } elseif ($filters['unit'] !== '') {
                    // A unit code alone is ambiguous across organizations: search it as text.
                    $query['q'] = trim($filters['q'] . ' ' . $filters['unit']);
                }
                $result = $api->search($query);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (PncpException $e) {
                $error = $e->budgetExhausted
                    ? __('PNCP took too long to answer. Try again in a few moments.', 'pncp')
                    : sprintf(__('PNCP error: %s', 'pncp'), $e->getMessage());
            }
        }

        TemplateRenderer::getInstance()->display('@pncp/search.html.twig', [
            'filters'    => $filters,
            'submitted'  => $submitted,
            'error'      => $error,
            'rows'       => self::rows($result['items'], $filters['type']),
            'total'      => $result['total'],
            'pages'      => (int) ceil(min($result['total'], 10000) / 25),
            'units'      => $unitsOfCnpj,
            'types'      => [
                'edital'   => Procurement::getTypeName(2),
                'contrato' => Contract::getTypeName(2),
                'ata'      => PriceRecord::getTypeName(2),
            ],
            'can_import' => Session::haveRight(Install::RIGHT, UPDATE),
            'can_watch'  => Watch::canCreate(),
            'watch_url'  => Watch::getFormURL(),
            'action'     => Menu::getSearchPageURL(),
        ]);
    }

    /** Units of a CNPJ (grouped by code) from the cache, fetched from the portal when unknown. */
    public static function units(Pncp $api, string $cnpj): array
    {
        $units = Store::groupedUnits($cnpj);
        if ($units === []) {
            Store::units($cnpj, $api->units($cnpj));
            $units = Store::groupedUnits($cnpj);
        }

        return $units;
    }

    /** Search hits shaped for the template, flagged when already imported. */
    private static function rows(array $items, string $type): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $controls = array_filter(array_column($items, 'numero_controle_pncp'));
        $imported = [];
        if ($controls !== []) {
            foreach ($DB->request(['SELECT' => ['id', 'control_number'], 'FROM' => self::TYPE_TABLE[$type], 'WHERE' => ['control_number' => $controls]]) as $r) {
                $imported[$r['control_number']] = self::TYPE_CLASS[$type]::getFormURLWithID($r['id']);
            }
        }

        $rows = [];
        foreach ($items as $i) {
            $cnpj = Util::ni($i['orgao_cnpj'] ?? '');
            $year = (int) ($i['ano'] ?? 0);
            $seq  = (int) ($i['numero_sequencial'] ?? 0);
            $key  = implode('|', [$type, $cnpj, $year, $seq, (int) ($i['numero_sequencial_compra_ata'] ?? 0), $i['numero_controle_pncp'] ?? '']);
            $rows[] = [
                'key'        => $key,
                'control'    => $i['numero_controle_pncp'] ?? '',
                'title'      => $i['title'] ?? '',
                'object'     => Util::truncate($i['description'] ?? '', 400),
                'org'        => $i['orgao_nome'] ?? '',
                'cnpj'       => $cnpj,
                'unit_code'  => $i['unidade_codigo'] ?? '',
                'unit_name'  => $i['unidade_nome'] ?? '',
                'town'       => trim(($i['municipio_nome'] ?? '') . '/' . ($i['uf'] ?? ''), '/'),
                'modality'   => $i['modalidade_licitacao_nome'] ?? '',
                'status'     => $i['situacao_nome'] ?? '',
                'supplier'   => trim(($i['fornecedor_nome'] ?? '') . (empty($i['fornecedor_ni']) ? '' : ' (' . Util::formatNi($i['fornecedor_ni']) . ')')),
                'value'      => Util::money(Util::num($i['valor_global'] ?? null) ?? Util::num($i['valor_total_estimado'] ?? null)),
                'date'       => Util::showDate($i['data_publicacao_pncp'] ?? null),
                'validity'   => trim(Util::showDate($i['data_inicio_vigencia'] ?? null) . ' → ' . Util::showDate($i['data_fim_vigencia'] ?? null), ' →'),
                'portal_url' => 'https://pncp.gov.br/app' . preg_replace('#^/compras/#', '/editais/', (string) ($i['item_url'] ?? '')),
                'imported'   => $imported[$i['numero_controle_pncp'] ?? ''] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Import the selected search hits. Keys are "type|cnpj|year|seq|procurementSeq|control".
     *
     * @param string[] $keys
     * @return array{ok: int, errors: string[], partial: bool}
     */
    public static function import(array $keys): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $api = Config::api((int) Config::get('web_budget'));
        $store = new Store(Session::getActiveEntity(), 0, 0);
        $syncer = new Syncer($api);
        $ok = 0;
        $errors = [];
        $partial = false;

        foreach ($keys as $key) {
            [$type, $cnpj, $year, $seq, $procSeq, $control] = array_pad(explode('|', (string) $key), 6, '');
            $cnpj = Util::ni($cnpj);
            $year = (int) $year;
            $seq = (int) $seq;
            try {
                switch ($type) {
                    case 'edital':
                        $ok += self::importProcurement($api, $store, $syncer, $cnpj, $year, $seq) ? 1 : 0;
                        break;
                    case 'contrato':
                        $raw = $api->contract($cnpj, $year, $seq);
                        if ($raw === null) {
                            throw new PncpException("Contract $control not found");
                        }
                        $id = (int) $store->contract($raw);
                        $f = (new Contract());
                        $f->getFromDB($id);
                        $store->amendments($id, $api->contractAmendments($cnpj, $year, $seq), $f->fields['date_update']);
                        $store->commitments($id, $api->contractCommitments($cnpj, $year, $seq), $f->fields['date_update']);
                        // The supplier comes along by default (GLPI Suppliers, matched by CNPJ/CPF).
                        if (\Supplier::canCreate() && $f->fields['supplier_ni']) {
                            $suppliersId = (new Exporter())->supplier($f->fields['supplier_ni'], $f->fields['supplier_name'], (int) $f->fields['entities_id']);
                            if ($suppliersId > 0) {
                                $DB->update(Contract::getTable(), ['suppliers_id' => $suppliersId], ['id' => $id]);
                            }
                        }
                        $ok++;
                        break;
                    case 'ata':
                        // The ata itself comes from the list of its procurement; the procurement
                        // (with items and winners) is imported too: suppliers live there.
                        $procSeq = (int) $procSeq;
                        $found = false;
                        foreach ($api->procurementPriceRecords($cnpj, $year, $procSeq) as $raw) {
                            if (Util::first($raw, 'numeroControlePNCPAta', 'numeroControlePNCP') === $control) {
                                $store->priceRecord($raw);
                                $found = true;
                            }
                        }
                        if (!$found) {
                            throw new PncpException("Price registration record $control not found on PNCP");
                        }
                        self::importProcurement($api, $store, $syncer, $cnpj, $year, $procSeq);
                        $ok++;
                        break;
                }
            } catch (PncpException $e) {
                if ($e->budgetExhausted) {
                    $partial = true;
                    break;
                }
                $errors[] = "$control: " . $e->getMessage();
            } catch (Throwable $e) {
                $errors[] = "$control: " . $e->getMessage();
            }
        }

        return ['ok' => $ok, 'errors' => $errors, 'partial' => $partial];
    }

    private static function importProcurement(Pncp $api, Store $store, Syncer $syncer, string $cnpj, int $year, int $seq): bool
    {
        $raw = $api->procurement($cnpj, $year, $seq);
        if ($raw === null) {
            throw new PncpException("Procurement $cnpj/$year/$seq not found");
        }
        $id = (int) $store->procurement($raw);
        $p = new Procurement();
        $p->getFromDB($id);
        try {
            $syncer->fetchItems($id, $cnpj, $year, $seq, $p->fields['date_update']);
        } catch (PncpException $e) {
            if (!$e->unavailable) {
                throw $e;
            }
            // Items stay pending; "Refresh from PNCP" on the record retries.
        }

        return true;
    }
}
