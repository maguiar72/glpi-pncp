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

namespace GlpiPlugin\Pncp\Api;

use DateTimeInterface;
use Generator;

/**
 * Typed access to the PNCP endpoints used by the plugin.
 *
 * Three hosts, which fail independently:
 *  - api/consulta: period listings (procurements, contracts, price records, PCA)
 *    and single procurement lookup;
 *  - api/pncp: organizations, units, items, results, contracts, amendments;
 *  - api/search: the full-text search used by the portal web site
 *    (undocumented but public), the only way to search by name.
 *
 * Every method returns the raw decoded JSON: the caller decides what to keep.
 */
final class Pncp
{
    /** Modalities of Law 14.133/2021: /v1/contratacoes/* requires looping over all of them. */
    public const MODALITIES = [
        1  => 'Leilão eletrônico',
        2  => 'Diálogo competitivo',
        3  => 'Concurso',
        4  => 'Concorrência eletrônica',
        5  => 'Concorrência presencial',
        6  => 'Pregão eletrônico',
        7  => 'Pregão presencial',
        8  => 'Dispensa de licitação',
        9  => 'Inexigibilidade',
        10 => 'Manifestação de interesse',
        11 => 'Pré-qualificação',
        12 => 'Credenciamento',
        13 => 'Leilão presencial',
    ];

    /** Document types accepted by the full-text search. */
    public const SEARCH_TYPES = ['edital', 'contrato', 'ata'];

    public const PCA_FIRST_DATE = '2021-04-01';

    public function __construct(
        public readonly Client $client,
    ) {}

    // ── organizations ─────────────────────────────────────────────────────

    /** Organization record, or null when the CNPJ is not registered on the portal. */
    public function organization(string $cnpj): ?array
    {
        $data = $this->client->get(Client::BASE_PNCP, "/v1/orgaos/$cnpj");

        return is_array($data) ? $data : null;
    }

    /** Administrative units of an organization (with the internal ids used by the search filters). */
    public function units(string $cnpj): array
    {
        $data = $this->client->get(Client::BASE_PNCP, "/v1/orgaos/$cnpj/unidades");
        if (is_array($data) && isset($data['data'])) {
            $data = $data['data'];
        }

        return is_array($data) ? $data : [];
    }

    // ── full-text search ──────────────────────────────────────────────────

    /**
     * Full-text search, as on https://pncp.gov.br/app/editais.
     *
     * Several unit ids (the same unit registered with different spellings) are
     * OR-ed with "|", as the portal web site does.
     *
     * @param array{q?: string, type?: string, org_pncp_id?: int, unit_pncp_ids?: int[],
     *              year?: int, state?: string, status?: string, page?: int, size?: int} $f
     * @return array{items: array, total: int}
     */
    public function search(array $f): array
    {
        $query = [
            'tipos_documento' => in_array($f['type'] ?? '', self::SEARCH_TYPES, true) ? $f['type'] : 'edital',
            'ordenacao'       => '-data',
            'pagina'          => max(1, (int) ($f['page'] ?? 1)),
            'tam_pagina'      => min(100, max(1, (int) ($f['size'] ?? 20))),
            'status'          => $f['status'] ?? 'todos',
        ];
        if (($f['q'] ?? '') !== '') {
            $query['q'] = $f['q'];
        }
        if (!empty($f['org_pncp_id'])) {
            $query['orgaos'] = (int) $f['org_pncp_id'];
        }
        if (!empty($f['unit_pncp_ids'])) {
            $query['unidades'] = implode('|', array_map('intval', (array) $f['unit_pncp_ids']));
        }
        if (!empty($f['year'])) {
            $query['anos'] = (int) $f['year'];
        }
        if (!empty($f['state'])) {
            $query['ufs'] = strtoupper((string) $f['state']);
        }
        // 6 attempts: the search host resets connections often (see Client::isThrottleReset);
        // the web time budget still bounds the total wait.
        $data = $this->client->get(Client::BASE_SEARCH, '/', $query, Client::ON404_RETRY, 6);

        return [
            'items' => is_array($data) ? ($data['items'] ?? []) : [],
            'total' => is_array($data) ? (int) ($data['total'] ?? 0) : 0,
        ];
    }

    // ── procurements (contratações) ───────────────────────────────────────

    /**
     * Procurements of an organization updated in a window, for one modality.
     *
     * @return Generator<int, array>
     */
    public function procurementsUpdated(string $cnpj, ?string $unitCode, DateTimeInterface $from, DateTimeInterface $to, int $modality): Generator
    {
        $query = [
            'dataInicial'                 => $from->format('Ymd'),
            'dataFinal'                   => $to->format('Ymd'),
            'codigoModalidadeContratacao' => $modality,
            'cnpj'                        => $cnpj,
        ];
        if ($unitCode !== null && $unitCode !== '') {
            $query['codigoUnidadeAdministrativa'] = $unitCode;
        }

        yield from $this->client->paginate(Client::BASE_CONSULTA, '/v1/contratacoes/atualizacao', $query, 50);
    }

    public function procurement(string $cnpj, int $year, int $sequential): ?array
    {
        $data = $this->client->get(Client::BASE_CONSULTA, "/v1/orgaos/$cnpj/compras/$year/$sequential");

        return is_array($data) ? $data : null;
    }

    /**
     * Items of a procurement. The endpoint returns a bare array with no envelope:
     * a page shorter than the page size is the end (an empty page after a full one
     * is legitimate when the count is a multiple of the page size).
     *
     * @return Generator<int, array>
     */
    public function procurementItems(string $cnpj, int $year, int $sequential): Generator
    {
        $page = 1;
        while (true) {
            $batch = $this->client->get(
                Client::BASE_PNCP,
                "/v1/orgaos/$cnpj/compras/$year/$sequential/itens",
                ['pagina' => $page, 'tamanhoPagina' => 100],
                Client::ON404_UNAVAILABLE,
            );
            if (!is_array($batch) || $batch === []) {
                return;
            }
            yield from $batch;
            if (count($batch) < 100) {
                return;
            }
            $page++;
        }
    }

    /** Current (latest non-cancelled) result of an item, or null when it has none. */
    public function itemResult(string $cnpj, int $year, int $sequential, int $itemNumber): ?array
    {
        $list = $this->client->get(Client::BASE_PNCP, "/v1/orgaos/$cnpj/compras/$year/$sequential/itens/$itemNumber/resultados");
        if (!is_array($list) || $list === []) {
            return null;
        }
        $valid = array_values(array_filter($list, static fn($r) => empty($r['dataCancelamento'])));

        return ($valid ?: $list)[0];
    }

    /** Price records (atas) generated by a procurement. */
    public function procurementPriceRecords(string $cnpj, int $year, int $sequential): array
    {
        $data = $this->client->get(Client::BASE_PNCP, "/v1/orgaos/$cnpj/compras/$year/$sequential/atas");

        return is_array($data) ? ($data['data'] ?? $data) : [];
    }

    // ── published files ───────────────────────────────────────────────────

    /**
     * Files published for a record: kind "compras" (procurement), "contratos"
     * (contract) or "atas" (price record, $ataSequential required).
     * Each file has url, titulo, tipoDocumentoNome, sequencialDocumento, dataPublicacaoPncp.
     */
    public function files(string $kind, string $cnpj, int $year, int $sequential, ?int $ataSequential = null): array
    {
        $path = match ($kind) {
            'compras'   => "/v1/orgaos/$cnpj/compras/$year/$sequential/arquivos",
            'contratos' => "/v1/orgaos/$cnpj/contratos/$year/$sequential/arquivos",
            'atas'      => "/v1/orgaos/$cnpj/compras/$year/$sequential/atas/$ataSequential/arquivos",
            default     => throw new \InvalidArgumentException("Unknown file kind $kind"),
        };
        $list = $this->client->get(Client::BASE_PNCP, $path);
        if (!is_array($list)) {
            return [];
        }
        // Withdrawn files stay listed with statusAtivo=false.
        return array_values(array_filter($list, static fn($f) => ($f['statusAtivo'] ?? true) !== false));
    }

    // ── contracts ─────────────────────────────────────────────────────────

    /** @return Generator<int, array> */
    public function contractsUpdated(string $cnpj, DateTimeInterface $from, DateTimeInterface $to): Generator
    {
        yield from $this->client->paginate(Client::BASE_CONSULTA, '/v1/contratos/atualizacao', [
            'dataInicial' => $from->format('Ymd'),
            'dataFinal'   => $to->format('Ymd'),
            'cnpjOrgao'   => $cnpj,
        ], 500);
    }

    public function contract(string $cnpj, int $year, int $sequential): ?array
    {
        $data = $this->client->get(Client::BASE_PNCP, "/v1/orgaos/$cnpj/contratos/$year/$sequential");

        return is_array($data) ? $data : null;
    }

    /** Amendments (termos aditivos) of a contract; checks the cheap counter first. */
    public function contractAmendments(string $cnpj, int $year, int $sequential): array
    {
        $count = $this->client->get(
            Client::BASE_PNCP,
            "/v1/orgaos/$cnpj/contratos/$year/$sequential/termos/quantidade",
            [],
            Client::ON404_UNAVAILABLE,
        );
        if ((int) $count === 0) {
            return [];
        }
        $list = $this->client->get(
            Client::BASE_PNCP,
            "/v1/orgaos/$cnpj/contratos/$year/$sequential/termos",
            [],
            Client::ON404_UNAVAILABLE,
        );

        return is_array($list) ? $list : [];
    }

    /**
     * Commitment notes (empenhos) linked to a contract. The portal answers 404
     * ("Nenhum empenho do contrato") for a contract without any: that is an empty
     * list, not a failure.
     */
    public function contractCommitments(string $cnpj, int $year, int $sequential): array
    {
        $list = [];
        for ($page = 1;; $page++) {
            try {
                $data = $this->client->get(
                    Client::BASE_PNCP,
                    "/v1/orgaos/$cnpj/contratos/$year/$sequential/empenhos",
                    ['pagina' => $page, 'tamanhoPagina' => 100],
                    Client::ON404_UNAVAILABLE,
                );
            } catch (PncpException $e) {
                if ($e->unavailable) {
                    return $list;
                }
                throw $e;
            }
            $records = is_array($data) ? ($data['data'] ?? []) : [];
            foreach ($records as $raw) {
                $list[] = $raw;
            }
            $remaining = is_array($data) ? (int) ($data['paginasRestantes'] ?? 0) : 0;
            if ($records === [] || $remaining <= 0) {
                return $list;
            }
        }
    }

    // ── price records (atas) ──────────────────────────────────────────────

    /** @return Generator<int, array> */
    public function priceRecordsUpdated(string $cnpj, DateTimeInterface $from, DateTimeInterface $to): Generator
    {
        yield from $this->client->paginate(Client::BASE_CONSULTA, '/v1/atas/atualizacao', [
            'dataInicial' => $from->format('Ymd'),
            'dataFinal'   => $to->format('Ymd'),
            'cnpj'        => $cnpj,
        ], 500);
    }

    // ── annual procurement plan (PCA) ─────────────────────────────────────

    /**
     * PCA plans updated in a window. This endpoint uses dataInicio/dataFim and
     * rejects dates before 2021-04-01.
     *
     * @return Generator<int, array>
     */
    public function pcaUpdated(string $cnpj, DateTimeInterface $from, DateTimeInterface $to): Generator
    {
        $begin = max($from->format('Y-m-d'), self::PCA_FIRST_DATE);
        if ($begin > $to->format('Y-m-d')) {
            return;
        }
        yield from $this->client->paginate(Client::BASE_CONSULTA, '/v1/pca/atualizacao', [
            'dataInicio' => str_replace('-', '', $begin),
            'dataFim'    => $to->format('Ymd'),
            'cnpj'       => $cnpj,
        ], 500);
    }
}
