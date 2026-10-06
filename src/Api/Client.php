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

use Generator;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use Toolbox;

/**
 * Resilient HTTP client for the PNCP public APIs.
 *
 * Ported from the `motor_pncp` Python package used by Licitarium. Lessons kept:
 *  - requests are sequential with a minimum interval: measured against the real
 *    portal, parallel connections only multiply 429 answers from its WAF;
 *  - 429/5xx/timeouts/HTML bodies are transient: retry with full-jitter backoff,
 *    honouring Retry-After (capped);
 *  - a 404 on a listing can be the portal under load, never "no data";
 *  - an empty page in the middle of an announced pagination is a failure;
 *  - every run has a deadline, so a cron run or a web request never hangs.
 */
final class Client
{
    public const BASE_CONSULTA = 'https://pncp.gov.br/api/consulta';
    public const BASE_PNCP     = 'https://pncp.gov.br/api/pncp';
    public const BASE_SEARCH   = 'https://pncp.gov.br/api/search';

    /** Transient statuses (422 was observed spuriously under load, retried only once). */
    private const TRANSIENT = [408, 422, 425, 500, 502, 503, 504];

    /** 404 means "no record" (single record lookups). */
    public const ON404_NULL = 'null';
    /** 404 is the portal under load: retry, then fail (paginated listings). */
    public const ON404_RETRY = 'retry';
    /** 404 raises PncpException::unavailable() at once (items, amendments). */
    public const ON404_UNAVAILABLE = 'unavailable';

    private HttpClient $http;
    private float $lastRequest = 0.0;
    private ?float $deadline = null;

    public int $requests = 0;
    public int $retries = 0;

    /**
     * @param array{user_agent?: string, min_interval?: float, attempts?: int,
     *              timeouts?: int[], retry_after_cap?: int} $options
     */
    public function __construct(
        private array $options = [],
    ) {
        $this->options += [
            'user_agent'      => 'GLPI-PNCP-plugin/' . (defined('PLUGIN_PNCP_VERSION') ? PLUGIN_PNCP_VERSION : 'dev'),
            'min_interval'    => 0.5,
            'attempts'        => 5,
            'timeouts'        => [30, 45, 60, 75, 90],
            'retry_after_cap' => 120,
        ];
        $this->http = Toolbox::getGuzzleClient([
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent' => $this->options['user_agent'],
                'Accept'     => 'application/json',
            ],
        ]);
    }

    /** Absolute unix timestamp after which no new request (or wait) is started. */
    public function setDeadline(?float $deadline): void
    {
        $this->deadline = $deadline;
    }

    public function remainingTime(): ?float
    {
        return $this->deadline === null ? null : $this->deadline - microtime(true);
    }

    /**
     * GET a JSON resource. Returns the decoded body, or null for 204/empty body
     * (and for 404 when $on404 is ON404_NULL).
     *
     * @param array<string, scalar> $query
     */
    public function get(string $base, string $path, array $query = [], string $on404 = self::ON404_NULL, ?int $attempts = null): mixed
    {
        // Path segments may come from user input (CNPJ, year, sequential): encode
        // everything but "/" so they can never rewrite the request.
        $url = $base . implode('/', array_map('rawurlencode', explode('/', $path)));
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $attempts ??= (int) $this->options['attempts'];

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $last = $attempt === $attempts - 1;
            $this->pace();
            $this->requests++;
            try {
                $response = $this->http->get($url, ['timeout' => $this->timeout($attempt)]);
            } catch (ConnectException | TransferException $e) {
                if (!$last) {
                    // The portal WAF also throttles by resetting the connection (cURL 56/52,
                    // a few ms after connecting). Measured 2026-10-04: at one request per
                    // second ~35% are reset, up to 4 in a row; it clears within seconds.
                    // Treat it like a 429 without Retry-After: a growing, non-zero wait.
                    $this->wait(self::isThrottleReset($e)
                        ? min(15.0, (mt_rand(200, 400) / 100) * ($attempt + 1))
                        : $this->backoff($attempt));
                    continue;
                }
                throw new PncpException(
                    str_contains(strtolower($e->getMessage()), 'timed out')
                        ? "PNCP did not answer in {$this->timeout($attempt)}s on $path (portal slow or offline)"
                        : "No connection to PNCP on $path: " . $e->getMessage(),
                    0,
                    $e,
                );
            }

            $status = $response->getStatusCode();
            if ($status === 204) {
                return null;
            }
            if ($status >= 200 && $status < 300) {
                $body = trim((string) $response->getBody());
                if ($body === '') {
                    return null; // some endpoints (PCA) answer 200 with an empty body
                }
                $data = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                }
                // 200 with an HTML error page: the portal under load.
                if (!$last) {
                    $this->wait($this->backoff($attempt));
                    continue;
                }
                throw new PncpException("PNCP returned an unreadable (non JSON) response on $path");
            }

            if ($status === 404) {
                if ($on404 === self::ON404_NULL) {
                    return null;
                }
                if ($on404 === self::ON404_UNAVAILABLE) {
                    throw PncpException::unavailable($path);
                }
                if (!$last) {
                    $this->wait($this->backoff($attempt));
                    continue;
                }
                throw new PncpException("Persistent HTTP 404 on listing $path; aborting instead of treating the window as empty");
            }

            if ($status === 429 && !$last) {
                $this->wait($this->retryAfter($response) ?? (mt_rand(250, 500) / 100) * ($attempt + 1));
                continue;
            }
            $ceiling = $status === 422 ? min($attempts, 2) : $attempts;
            if (in_array($status, self::TRANSIENT, true) && $attempt < $ceiling - 1) {
                $this->wait(($status === 503 ? $this->retryAfter($response) : null) ?? $this->backoff($attempt));
                continue;
            }

            throw new PncpException("HTTP $status on $path" . $this->reason($response));
        }

        throw new PncpException("Request to $path failed"); // unreachable
    }

    /**
     * Download a file published on the portal to $destination. Only URLs on
     * pncp.gov.br are accepted (they come from the portal's own file listings).
     * Returns the file name announced by the portal (Content-Disposition).
     */
    public function download(string $url, string $destination, ?int $attempts = null): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'pncp.gov.br') {
            throw new PncpException("Refusing to download from outside pncp.gov.br: $url");
        }
        $attempts ??= (int) $this->options['attempts'];
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $last = $attempt === $attempts - 1;
            $this->pace();
            $this->requests++;
            try {
                $response = $this->http->get($url, [
                    'timeout' => 120 + 60 * $attempt,
                    'sink'    => $destination,
                    'headers' => ['Accept' => '*/*'],
                ]);
            } catch (ConnectException | TransferException $e) {
                if (!$last) {
                    $this->wait(self::isThrottleReset($e) ? min(15.0, (mt_rand(200, 400) / 100) * ($attempt + 1)) : $this->backoff($attempt));
                    continue;
                }
                throw new PncpException('Download failed: ' . $e->getMessage(), 0, $e);
            }
            $status = $response->getStatusCode();
            if ($status === 200) {
                if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $response->getHeaderLine('Content-Disposition'), $m)) {
                    return basename(rawurldecode($m[1]));
                }

                return basename((string) ($parts['path'] ?? 'file'));
            }
            if (!$last && ($status === 429 || in_array($status, self::TRANSIENT, true))) {
                $this->wait($this->retryAfter($response) ?? $this->backoff($attempt));
                continue;
            }
            throw new PncpException("HTTP $status downloading $url");
        }

        throw new PncpException("Download of $url failed"); // unreachable
    }

    /**
     * Iterate over the records of an enveloped, paginated listing
     * (`data` + `paginasRestantes`/`totalPaginas`).
     *
     * @param array<string, scalar> $query
     * @return Generator<int, array>
     */
    public function paginate(string $base, string $path, array $query, int $pageSize): Generator
    {
        $page = 1;
        while (true) {
            $data = $this->get($base, $path, $query + ['pagina' => $page, 'tamanhoPagina' => $pageSize], self::ON404_RETRY);
            $records = is_array($data) ? ($data['data'] ?? []) : [];
            if ($records === [] || $records === null) {
                if ($page > 1) {
                    throw new PncpException("Pagination interrupted on $path: page $page came back empty but more pages were announced");
                }
                return; // 204 or an empty first page: no records in this window
            }
            yield from $records;

            $remaining = $data['paginasRestantes'] ?? null;
            if ($remaining === null) {
                if (!isset($data['totalPaginas'])) {
                    throw new PncpException("Envelope without paginasRestantes/totalPaginas on $path (page $page)");
                }
                $remaining = (int) $data['totalPaginas'] - $page;
            }
            if ((int) $remaining <= 0) {
                return;
            }
            $page++;
        }
    }

    /** Connection reset / empty reply right after connecting: the WAF throttling, not an outage. */
    private static function isThrottleReset(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'cURL error 56')
            || str_contains($message, 'cURL error 52')
            || stripos($message, 'connection reset') !== false
            || stripos($message, 'empty reply') !== false;
    }

    private function pace(): void
    {
        $this->assertTime(0);
        $wait = (float) $this->options['min_interval'] - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
    }

    private function wait(float $seconds): void
    {
        $this->retries++;
        $this->assertTime($seconds);
        usleep((int) ($seconds * 1_000_000));
    }

    /** Throw when the deadline would be crossed by waiting $seconds and doing one more request. */
    private function assertTime(float $seconds): void
    {
        if ($this->deadline !== null && microtime(true) + $seconds + 5 > $this->deadline) {
            throw PncpException::budget();
        }
    }

    private function timeout(int $attempt): int
    {
        $timeouts = $this->options['timeouts'];

        return (int) ($timeouts[min($attempt, count($timeouts) - 1)]);
    }

    /** Full-jitter exponential backoff: uniform in [0, 2^attempt], at least 0.5s. */
    private function backoff(int $attempt): float
    {
        return max(0.5, mt_rand(0, (int) (1000 * 2 ** min($attempt + 1, 4))) / 1000);
    }

    private function retryAfter(ResponseInterface $response): ?float
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $seconds = (float) $value;
        } else {
            $ts = strtotime($value);
            if ($ts === false) {
                return null;
            }
            $seconds = max(0, $ts - time());
        }

        return min($seconds, (float) $this->options['retry_after_cap']);
    }

    private function reason(ResponseInterface $response): string
    {
        $body = trim(preg_replace('/\s+/', ' ', (string) $response->getBody()) ?? '');
        if ($body === '' || str_starts_with($body, '<')) {
            return '';
        }

        return ' — ' . mb_substr($body, 0, 200);
    }
}
