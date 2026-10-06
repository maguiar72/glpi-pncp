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

/**
 * Persistence of raw portal records into the plugin tables.
 *
 * Plain SQL upserts on purpose: synchronization writes thousands of rows and
 * must not trigger GLPI item hooks, history or notifications for each one.
 * Every upsert is keyed by the PNCP control number and is idempotent.
 */
final class Store
{
    /** Context applied to new records: owning entity and monitored scope. */
    public function __construct(
        private int $entitiesId = 0,
        private int $isRecursive = 0,
        private int $watchesId = 0,
    ) {}

    public static function forWatch(Watch $watch): self
    {
        return new self((int) $watch->fields['entities_id'], (int) $watch->fields['is_recursive'], (int) $watch->getID());
    }

    /** Upsert by unique key; returns the row id. Ownership columns are only set on insert. */
    private function upsert(string $table, array $key, array $values, bool $owned = true): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $row = $DB->request(['SELECT' => 'id', 'FROM' => $table, 'WHERE' => $key, 'LIMIT' => 1])->current();
        if ($row !== null) {
            $DB->update($table, $values + ['date_mod' => $now], ['id' => $row['id']]);

            return (int) $row['id'];
        }
        $insert = $key + $values + ['date_mod' => $now, 'date_creation' => $now];
        if ($owned) {
            $insert += [
                'entities_id'            => $this->entitiesId,
                'is_recursive'           => $this->isRecursive,
                'plugin_pncp_watches_id' => $this->watchesId,
            ];
        }
        $DB->insert($table, $insert);

        return (int) $DB->insertId();
    }

    // ── procurements ──────────────────────────────────────────────────────

    public function procurement(array $raw): ?int
    {
        $control = $raw['numeroControlePNCP'] ?? null;
        if (!$control) {
            return null;
        }
        $org  = $raw['orgaoEntidade'] ?? [];
        $unit = $raw['unidadeOrgao'] ?? [];
        $cnpj = Util::ni($org['cnpj'] ?? '');
        $year = (int) ($raw['anoCompra'] ?? 0);
        $seq  = (int) ($raw['sequencialCompra'] ?? 0);
        $object = $raw['objetoCompra'] ?? null;

        return $this->upsert('glpi_plugin_pncp_procurements', ['control_number' => $control], [
            // Short title describing the procurement; the full object has its own column.
            'name'              => Util::title($raw['modalidadeNome'] ?? null, (string) ($raw['numeroCompra'] ?? ''), $year, $object),
            'cnpj'              => $cnpj,
            'org_name'          => Util::truncate($org['razaoSocial'] ?? null, 255),
            'unit_code'         => Util::unitCode($unit['codigoUnidade'] ?? '') ?: null,
            'unit_name'         => Util::truncate($unit['nomeUnidade'] ?? null, 255),
            'year'              => $year,
            'sequential'        => $seq,
            'number'            => Util::truncate((string) ($raw['numeroCompra'] ?? ''), 50),
            'process'           => Util::truncate($raw['processo'] ?? null, 255),
            'modality_id'       => (int) ($raw['modalidadeId'] ?? 0),
            'modality_name'     => Util::truncate($raw['modalidadeNome'] ?? null, 100),
            'status_name'       => Util::truncate($raw['situacaoCompraNome'] ?? null, 100),
            'legal_basis'       => Util::truncate($raw['amparoLegal']['nome'] ?? null, 255),
            'object'            => $object,
            'estimated_value'   => Util::num($raw['valorTotalEstimado'] ?? null),
            'awarded_value'     => Util::num($raw['valorTotalHomologado'] ?? null),
            'is_srp'            => empty($raw['srp']) ? 0 : 1,
            'date_publication'  => Util::datetime($raw['dataPublicacaoPncp'] ?? null),
            'date_proposal_end' => Util::datetime($raw['dataEncerramentoProposta'] ?? null),
            'date_update'       => Util::datetime(Util::first($raw, 'dataAtualizacaoGlobal', 'dataAtualizacao')),
            'source_url'        => Util::truncate($raw['linkSistemaOrigem'] ?? null, 500),
            'raw'               => Util::json($raw),
            'date_sync'         => date('Y-m-d H:i:s'),
        ]);
    }

    /** Items + results of one procurement; marks the items version as synchronized. */
    public function procurementItems(int $procurementsId, array $pairs, ?string $version): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $n = 0;
        foreach ($pairs as [$item, $result]) {
            $number = $item['numeroItem'] ?? null;
            if ($number === null) {
                continue;
            }
            $r = $result ?? [];
            $this->upsert('glpi_plugin_pncp_procurementitems', [
                'plugin_pncp_procurements_id' => $procurementsId,
                'item_number'                 => (int) $number,
            ], [
                'name'                  => $item['descricao'] ?? null,
                'material_or_service'   => Util::truncate($item['materialOuServicoNome'] ?? null, 50),
                'category_name'         => Util::truncate($item['itemCategoriaNome'] ?? null, 255),
                'measure_unit'          => Util::truncate($item['unidadeMedida'] ?? null, 100),
                'quantity'              => Util::num($item['quantidade'] ?? null),
                'estimated_unit_value'  => Util::num($item['valorUnitarioEstimado'] ?? null),
                'estimated_total_value' => Util::num($item['valorTotal'] ?? null),
                'status_name'           => Util::truncate($item['situacaoCompraItemNome'] ?? null, 100),
                'has_result'            => empty($item['temResultado']) ? 0 : 1,
                'supplier_ni'           => Util::ni($r['niFornecedor'] ?? '') ?: null,
                'supplier_name'         => Util::truncate($r['nomeRazaoSocialFornecedor'] ?? null, 255),
                'supplier_size'         => Util::truncate($r['porteFornecedorNome'] ?? null, 100),
                'awarded_quantity'      => Util::num($r['quantidadeHomologada'] ?? null),
                'awarded_unit_value'    => Util::num($r['valorUnitarioHomologado'] ?? null),
                'awarded_total_value'   => Util::num($r['valorTotalHomologado'] ?? null),
                'date_result'           => Util::datetime($r['dataResultado'] ?? null),
                'date_update'           => Util::datetime($item['dataAtualizacao'] ?? null),
                'raw'                   => Util::json(['item' => $item, 'resultado' => $result]),
            ], false);
            $n++;
        }
        $DB->update('glpi_plugin_pncp_procurements', [
            'items_version'   => $version,
            'date_items_sync' => date('Y-m-d H:i:s'),
        ], ['id' => $procurementsId]);

        return $n;
    }

    // ── contracts ─────────────────────────────────────────────────────────

    public function contract(array $raw): ?int
    {
        $control = $raw['numeroControlePNCP'] ?? null;
        if (!$control) {
            return null;
        }
        $org    = $raw['orgaoEntidade'] ?? [];
        $unit   = $raw['unidadeOrgao'] ?? [];
        $number = (string) ($raw['numeroContratoEmpenho'] ?? '');
        $year   = (int) ($raw['anoContrato'] ?? 0);
        $object = $raw['objetoContrato'] ?? null;
        $type   = $raw['tipoContrato']['nome'] ?? null;

        $id = $this->upsert('glpi_plugin_pncp_contracts', ['control_number' => $control], [
            'name'                       => Util::title($type, $number, $year, $object),
            'procurement_control_number' => Util::first($raw, 'numeroControlePncpCompra', 'numeroControlePNCPCompra'),
            'cnpj'                       => Util::ni($org['cnpj'] ?? ''),
            'org_name'                   => Util::truncate($org['razaoSocial'] ?? null, 255),
            'unit_code'                  => Util::unitCode($unit['codigoUnidade'] ?? '') ?: null,
            'unit_name'                  => Util::truncate($unit['nomeUnidade'] ?? null, 255),
            'number'                     => Util::truncate($number, 100),
            'year'                       => $year,
            'sequential'                 => (int) ($raw['sequencialContrato'] ?? 0),
            'type_name'                  => Util::truncate($type, 100),
            'category_name'              => Util::truncate($raw['categoriaProcesso']['nome'] ?? null, 100),
            'process'                    => Util::truncate($raw['processo'] ?? null, 255),
            'supplier_ni'                => Util::ni($raw['niFornecedor'] ?? '') ?: null,
            'supplier_name'              => Util::truncate($raw['nomeRazaoSocialFornecedor'] ?? null, 255),
            'object'                     => $object,
            'initial_value'              => Util::num($raw['valorInicial'] ?? null),
            'global_value'               => Util::num($raw['valorGlobal'] ?? null),
            'date_signature'             => Util::date($raw['dataAssinatura'] ?? null),
            'begin_date'                 => Util::date(Util::first($raw, 'dataVigenciaInicio', 'vigenciaInicio')),
            'end_date'                   => Util::date(Util::first($raw, 'dataVigenciaFim', 'vigenciaFim')),
            'date_publication'           => Util::datetime($raw['dataPublicacaoPncp'] ?? null),
            'date_update'                => Util::datetime(Util::first($raw, 'dataAtualizacaoGlobal', 'dataAtualizacao')),
            'raw'                        => Util::json($raw),
            'date_sync'                  => date('Y-m-d H:i:s'),
        ]);
        Lifecycle::refresh([$id]);

        return $id;
    }

    public function amendments(int $contractsId, array $list, ?string $version): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $n = 0;
        foreach ($list as $raw) {
            $seq = Util::first($raw, 'sequencialTermoContrato', 'sequencialTermo');
            if ($seq === null) {
                continue;
            }
            $type = $raw['tipoTermoContratoNome'] ?? ($raw['tipoTermoContrato']['nome'] ?? null);
            $this->upsert('glpi_plugin_pncp_amendments', [
                'plugin_pncp_contracts_id' => $contractsId,
                'sequential'               => (int) $seq,
            ], [
                'name'           => Util::truncate(trim(($type ?? '') . ' ' . ($raw['numeroTermoContrato'] ?? $seq)), 255),
                'type_name'      => Util::truncate($type, 100),
                'object'         => Util::first($raw, 'objetoTermoContrato', 'fundamentoLegal'),
                'added_value'    => Util::num(Util::first($raw, 'valorAcrescido', 'valorAcrescimo')),
                'global_value'   => Util::num($raw['valorGlobal'] ?? null),
                'date_signature' => Util::date($raw['dataAssinatura'] ?? null),
                'begin_date'     => Util::date($raw['dataVigenciaInicio'] ?? null),
                'end_date'       => Util::date($raw['dataVigenciaFim'] ?? null),
                'raw'            => Util::json($raw),
            ], false);
            $n++;
        }
        $DB->update('glpi_plugin_pncp_contracts', ['amendments_version' => $version], ['id' => $contractsId]);
        Lifecycle::refresh([$contractsId]);

        return $n;
    }

    public function commitments(int $contractsId, array $list, ?string $version): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $n = 0;
        foreach ($list as $raw) {
            $seq = $raw['sequencialEmpenho'] ?? null;
            if ($seq === null) {
                continue;
            }
            $this->upsert('glpi_plugin_pncp_commitments', [
                'plugin_pncp_contracts_id' => $contractsId,
                'sequential'               => (int) $seq,
            ], [
                'number'         => Util::truncate($raw['numeroEmpenho'] ?? null, 50),
                'status_name'    => Util::truncate($raw['situacaoEmpenhoNome'] ?? null, 100),
                'status_date'    => Util::datetime($raw['dataSituacaoEmpenho'] ?? null),
                'issue_date'     => Util::date($raw['dataEmissaoEmpenho'] ?? null),
                'creditor_ni'    => Util::ni($raw['niCredorFornecedor'] ?? '') ?: null,
                'creditor_name'  => Util::truncate($raw['nomeCredorFornecedor'] ?? null, 255),
                'expense_nature' => Util::truncate($raw['codigoNaturezaDespesa'] ?? null, 20),
                'internal_plan'  => Util::truncate($raw['numeroPlanoInterno'] ?? null, 50),
                'total_value'    => Util::num($raw['valorTotal'] ?? null),
                'raw'            => Util::json($raw),
            ], false);
            $n++;
        }
        $DB->update('glpi_plugin_pncp_contracts', ['commitments_version' => $version], ['id' => $contractsId]);

        return $n;
    }

    // ── price records (atas) ──────────────────────────────────────────────

    public function priceRecord(array $raw): ?int
    {
        $control = Util::first($raw, 'numeroControlePNCPAta', 'numeroControlePNCP');
        if (!$control) {
            return null;
        }
        $number = (string) ($raw['numeroAtaRegistroPreco'] ?? '');
        $year   = (int) ($raw['anoAta'] ?? 0);
        $object = Util::first($raw, 'objetoContratacao', 'objetoCompra');

        return $this->upsert('glpi_plugin_pncp_pricerecords', ['control_number' => $control], [
            'name'                       => Util::title('ARP', $number, $year, $object),
            'procurement_control_number' => Util::first($raw, 'numeroControlePNCPCompra', 'numeroControlePncpCompra'),
            'cnpj'                       => Util::ni((string) Util::first($raw, 'cnpjOrgao', 'cnpj')),
            'org_name'                   => Util::truncate($raw['nomeOrgao'] ?? null, 255),
            'unit_code'                  => Util::unitCode($raw['codigoUnidadeOrgao'] ?? '') ?: null,
            'unit_name'                  => Util::truncate($raw['nomeUnidadeOrgao'] ?? null, 255),
            'number'                     => Util::truncate($number, 50),
            'year'                       => $year,
            'object'                     => $object,
            'is_cancelled'               => empty($raw['cancelado']) ? 0 : 1,
            'date_signature'             => Util::date($raw['dataAssinatura'] ?? null),
            'begin_date'                 => Util::date(Util::first($raw, 'vigenciaInicio', 'dataVigenciaInicio')),
            'end_date'                   => Util::date(Util::first($raw, 'vigenciaFim', 'dataVigenciaFim')),
            'date_publication'           => Util::datetime($raw['dataPublicacaoPncp'] ?? null),
            'date_update'                => Util::datetime(Util::first($raw, 'dataAtualizacaoGlobal', 'dataAtualizacao')),
            'raw'                        => Util::json($raw),
            'date_sync'                  => date('Y-m-d H:i:s'),
        ]);
    }

    // ── PCA ───────────────────────────────────────────────────────────────

    /** Flatten the items of one plan; the plan context goes into every row. */
    public function pcaPlan(array $plan): int
    {
        $id = $plan['idPcaPncp'] ?? null;
        if (!$id) {
            return 0;
        }
        $n = 0;
        foreach ($plan['itens'] ?? [] as $item) {
            $number = $item['numeroItem'] ?? null;
            if ($number === null) {
                continue;
            }
            $this->upsert('glpi_plugin_pncp_pcaitems', ['uid' => "$id#$number"], [
                'pca_id'        => $id,
                'year'          => (int) ($plan['anoPca'] ?? 0),
                'cnpj'          => Util::ni((string) Util::first($plan, 'orgaoEntidadeCnpj', 'cnpj')),
                'unit_code'     => Util::unitCode(Util::first($plan, 'codigoUnidade', 'codigoUnidadeOrgao') ?? '') ?: null,
                'unit_name'     => Util::truncate($plan['nomeUnidade'] ?? null, 255),
                'item_number'   => (int) $number,
                'name'          => Util::first($item, 'descricaoItem', 'nomeClassificacaoCatalogo'),
                'category_name' => Util::truncate($item['categoriaItemPcaNome'] ?? null, 255),
                'catalog_class' => Util::truncate($item['nomeClassificacaoCatalogo'] ?? null, 255),
                'group_name'    => Util::truncate($item['grupoContratacaoNome'] ?? null, 255),
                'quantity'      => Util::num($item['quantidadeEstimada'] ?? null),
                'unit_value'    => Util::num($item['valorUnitario'] ?? null),
                'total_value'   => Util::num($item['valorTotal'] ?? null),
                'date_desired'  => Util::date($item['dataDesejada'] ?? null),
                'date_update'   => Util::datetime($item['dataAtualizacao'] ?? null),
                'raw'           => Util::json($item),
                'date_sync'     => date('Y-m-d H:i:s'),
            ]);
            $n++;
        }

        return $n;
    }

    // ── units ─────────────────────────────────────────────────────────────

    /**
     * Units of a CNPJ grouped by normalized code (one entry per real unit).
     *
     * @return array<int, array{code: string, pncp_code: string, spellings: string[], pncp_ids: int[],
     *                          name: ?string, town: ?string, state: ?string, org_pncp_id: int}>
     */
    public static function groupedUnits(string $cnpj): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $groups = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_pncp_units', 'WHERE' => ['cnpj' => $cnpj], 'ORDER' => ['name', 'pncp_code']]) as $u) {
            $g = &$groups[$u['code']];
            $g ??= ['code' => $u['code'], 'pncp_code' => $u['pncp_code'], 'spellings' => [], 'pncp_ids' => [],
                'name' => $u['name'], 'town' => $u['town'], 'state' => $u['state'], 'org_pncp_id' => (int) $u['org_pncp_id']];
            $g['spellings'][] = $u['pncp_code'];
            $g['pncp_ids'][] = (int) $u['pncp_id'];
            // Display the longest spelling ("012345"), the one most endpoints use.
            if (strlen((string) $u['pncp_code']) > strlen((string) $g['pncp_code'])) {
                $g['pncp_code'] = $u['pncp_code'];
            }
            unset($g);
        }

        return array_values($groups);
    }

    /** Cache the unit catalogue of an organization; returns the number of units. */
    public static function units(string $cnpj, array $list): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now = date('Y-m-d H:i:s');
        $n = 0;
        foreach ($list as $u) {
            $spelling = trim((string) ($u['codigoUnidade'] ?? ''));
            $code = Util::unitCode($spelling);
            if ($code === '') {
                continue;
            }
            $values = [
                'code'        => $code,
                'org_name'    => Util::truncate($u['orgao']['razaoSocial'] ?? null, 255),
                'org_pncp_id' => (int) ($u['orgao']['id'] ?? 0),
                'pncp_id'     => (int) ($u['id'] ?? 0),
                'name'        => Util::truncate($u['nomeUnidade'] ?? null, 255),
                'town'        => Util::truncate($u['municipio']['nome'] ?? null, 255),
                'state'       => $u['municipio']['uf']['siglaUF'] ?? null,
                'ibge_code'   => $u['municipio']['codigoIbge'] ?? null,
                'is_active'   => array_key_exists('statusAtivo', $u) && !$u['statusAtivo'] ? 0 : 1,
                'date_mod'    => $now,
            ];
            $DB->updateOrInsert('glpi_plugin_pncp_units', $values, ['cnpj' => $cnpj, 'pncp_code' => $spelling]);
            $n++;
        }

        return $n;
    }
}
