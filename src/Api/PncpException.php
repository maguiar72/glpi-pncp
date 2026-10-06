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

use RuntimeException;

/**
 * Communication failure with the portal after retries were exhausted.
 *
 * A failure is never the same as "no records": callers must not advance a
 * synchronization cursor past a window that raised this exception.
 */
class PncpException extends RuntimeException
{
    /** The run's time budget ended before the request could complete; not a portal failure. */
    public bool $budgetExhausted = false;

    /** A 404 on a single-record listing (items of a procurement, amendments of a contract). */
    public bool $unavailable = false;

    public static function budget(): self
    {
        $e = new self('Time budget for this run is exhausted');
        $e->budgetExhausted = true;

        return $e;
    }

    public static function unavailable(string $path): self
    {
        $e = new self("HTTP 404 on $path (listing temporarily unavailable)");
        $e->unavailable = true;

        return $e;
    }
}
