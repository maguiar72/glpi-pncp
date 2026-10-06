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
use GlpiPlugin\Pncp\Api\Client;
use GlpiPlugin\Pncp\Api\Pncp;

/**
 * Plugin settings, stored in the core configuration table (context "plugin:pncp").
 */
final class Config
{
    public const CONTEXT = 'plugin:pncp';

    public const DEFAULTS = [
        // Days per query window. With a CNPJ filter, windows of a quarter or more
        // timed out on large organizations; one month answered (Licitarium, 2026-10).
        'window_procurements' => 31,
        'window_others'       => 364,
        // Seconds a cron run may spend talking to the portal.
        'cron_budget'         => 240,
        // Seconds an interactive "synchronize now" may spend.
        'web_budget'          => 50,
        // Minimum hours between two complete synchronizations of the same scope.
        'sync_interval'       => 6,
        // Minimum interval between two requests (seconds). Sequential on purpose.
        'min_interval'        => 0.5,
        'user_agent'          => '',
    ];

    private static ?array $cache = null;

    public static function installDefaults(): void
    {
        $current = GlpiConfig::getConfigurationValues(self::CONTEXT);
        GlpiConfig::setConfigurationValues(self::CONTEXT, array_diff_key(self::DEFAULTS, $current));
        self::$cache = null;
    }

    public static function get(string $key): mixed
    {
        self::$cache ??= GlpiConfig::getConfigurationValues(self::CONTEXT) + self::DEFAULTS;

        return self::$cache[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public static function set(array $values): void
    {
        $clean = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $clean[$key] = match (true) {
                is_int($default)   => max(1, (int) $values[$key]),
                is_float($default) => max(0.1, (float) str_replace(',', '.', (string) $values[$key])),
                default            => trim((string) $values[$key]),
            };
        }
        $clean['window_procurements'] = min(364, $clean['window_procurements'] ?? self::get('window_procurements'));
        $clean['window_others'] = min(364, $clean['window_others'] ?? self::get('window_others'));
        GlpiConfig::setConfigurationValues(self::CONTEXT, $clean);
        self::$cache = null;
    }

    /** API facade configured from the settings, with a deadline in $budget seconds. */
    public static function api(?int $budget = null): Pncp
    {
        $options = ['min_interval' => (float) self::get('min_interval')];
        if (self::get('user_agent') !== '') {
            $options['user_agent'] = (string) self::get('user_agent');
        }
        $client = new Client($options);
        if ($budget !== null) {
            $client->setDeadline(microtime(true) + $budget);
        }

        return new Pncp($client);
    }
}
