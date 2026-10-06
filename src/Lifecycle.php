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

use Config as GlpiConfig;
use State;

/**
 * Contract lifecycle, following the ITIL supplier-management stages
 * (planned → active → renewed/extended → expired → terminated), and its mapping
 * to GLPI "Statuses of items" (glpi_states) under a "Contract" parent.
 *
 * The status depends on dates, so it changes with time alone: it is recomputed
 * whenever a contract or its amendments are stored, and once a day by the cron.
 */
final class Lifecycle
{
    public const PLANNED  = 'planned';
    public const ACTIVE   = 'active';
    public const EXTENDED = 'extended';
    public const EXPIRED  = 'expired';
    public const INACTIVE = 'inactive';

    public const KEYS = [self::PLANNED, self::ACTIVE, self::EXTENDED, self::EXPIRED, self::INACTIVE];

    /**
     * Names of the GLPI states created by the plugin. Fixed in Portuguese on purpose:
     * they are shared data (not UI strings) and PNCP is a Brazilian-only portal.
     * Administrators may rename them; the plugin keeps their ids.
     */
    private const STATE_NAMES = [
        '_parent'      => 'Contrato',
        self::PLANNED  => 'A iniciar',
        self::ACTIVE   => 'Ativo',
        self::EXTENDED => 'Prorrogado',
        self::EXPIRED  => 'Vencido',
        self::INACTIVE => 'Inativo',
    ];

    public static function label(?string $key): string
    {
        return match ($key) {
            self::PLANNED  => __('Not started', 'pncp'),
            self::ACTIVE   => __('Active', 'pncp'),
            self::EXTENDED => __('Extended', 'pncp'),
            self::EXPIRED  => __('Expired', 'pncp'),
            self::INACTIVE => __('Inactive (terminated)', 'pncp'),
            default        => '',
        };
    }

    public static function badgeClass(?string $key): string
    {
        return match ($key) {
            self::PLANNED  => 'bg-azure-lt',
            self::ACTIVE   => 'bg-green-lt',
            self::EXTENDED => 'bg-teal-lt',
            self::EXPIRED  => 'bg-orange-lt',
            self::INACTIVE => 'bg-secondary-lt',
            default        => 'bg-secondary-lt',
        };
    }

    /**
     * Status of one contract from its dates and amendments.
     *
     * @param array $contract row of glpi_plugin_pncp_contracts
     * @param array $amendments rows of glpi_plugin_pncp_amendments
     */
    public static function compute(array $contract, array $amendments, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        $end = $contract['end_date'] ?? null;
        $extended = false;
        foreach ($amendments as $a) {
            $text = mb_strtolower(($a['type_name'] ?? '') . ' ' . ($a['object'] ?? ''));
            if (str_contains($text, 'rescis') || str_contains($text, 'distrato') || str_contains($text, 'encerramento')) {
                return self::INACTIVE;
            }
            $raw = json_decode((string) ($a['raw'] ?? ''), true) ?: [];
            if (!empty($a['end_date']) && ($end === null || $a['end_date'] > $end)) {
                $end = $a['end_date'];
                $extended = true;
            }
            if (!empty($raw['qualificacaoVigencia']) || (int) ($raw['prazoAditadoDias'] ?? 0) > 0) {
                $extended = true;
            }
        }
        if (!empty($contract['begin_date']) && $contract['begin_date'] > $today) {
            return self::PLANNED;
        }
        if ($end !== null && $end < $today) {
            return self::EXPIRED;
        }

        return $extended ? self::EXTENDED : self::ACTIVE;
    }

    /** Recompute and store the status of the given PNCP contract ids (all when null). Returns the number changed. */
    public static function refresh(?array $ids = null): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = $ids === null ? [] : ['id' => $ids ?: [0]];
        $amendments = [];
        $amWhere = $ids === null ? [] : ['plugin_pncp_contracts_id' => $ids ?: [0]];
        foreach ($DB->request(['FROM' => 'glpi_plugin_pncp_amendments', 'WHERE' => $amWhere]) as $a) {
            $amendments[$a['plugin_pncp_contracts_id']][] = $a;
        }
        $changed = 0;
        foreach ($DB->request(['SELECT' => ['id', 'begin_date', 'end_date', 'lifecycle'], 'FROM' => 'glpi_plugin_pncp_contracts', 'WHERE' => $where]) as $c) {
            $key = self::compute($c, $amendments[$c['id']] ?? []);
            if ($key !== $c['lifecycle']) {
                $DB->update('glpi_plugin_pncp_contracts', ['lifecycle' => $key], ['id' => $c['id']]);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * GLPI state id for a lifecycle key. The states are created on first use under
     * a "Contract" parent, visible for contracts only; their ids are kept in the
     * plugin configuration, so administrators may rename them freely.
     */
    public static function stateId(string $key): int
    {
        $ids = json_decode((string) (GlpiConfig::getConfigurationValues(Config::CONTEXT, ['state_ids'])['state_ids'] ?? ''), true) ?: [];
        $state = new State();
        if (isset($ids[$key]) && $state->getFromDB((int) $ids[$key])) {
            return (int) $ids[$key];
        }

        $parentId = (int) ($ids['_parent'] ?? 0);
        if ($parentId === 0 || !$state->getFromDB($parentId)) {
            $parentId = (int) (new State())->add([
                'name'                => self::STATE_NAMES['_parent'],
                'entities_id'         => 0,
                'is_recursive'        => 1,
                'comment'             => __('Contract lifecycle (PNCP plugin)', 'pncp'),
                'is_visible_contract' => 0,
            ]);
            $ids['_parent'] = $parentId;
        }
        $id = (int) (new State())->add([
            'name'                => self::STATE_NAMES[$key],
            'states_id'           => $parentId,
            'entities_id'         => 0,
            'is_recursive'        => 1,
            'is_visible_contract' => 1,
        ]);
        $ids[$key] = $id;
        GlpiConfig::setConfigurationValues(Config::CONTEXT, ['state_ids' => json_encode($ids)]);

        return $id;
    }
}
