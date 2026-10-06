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

use DateInterval;
use DateTimeImmutable;
use Generator;

final class Util
{
    /**
     * Identifier (CNPJ/CPF) without punctuation. Letters are kept: since July 2026
     * new CNPJs are alphanumeric (12 alphanumeric characters + 2 check digits).
     */
    public static function ni(?string $value): string
    {
        return preg_replace('/[^0-9A-Z]+/', '', strtoupper((string) $value)) ?? '';
    }

    /**
     * Format a supplier/organization identifier by its length: 14 characters is a
     * CNPJ, 11 digits a CPF (individuals do win procurements). Anything else is
     * kept as received (foreign identifiers).
     */
    public static function formatNi(?string $value): string
    {
        $d = self::ni($value);
        if (strlen($d) === 14) {
            return preg_replace('/^(.{2})(.{3})(.{3})(.{4})(.{2})$/', '$1.$2.$3/$4-$5', $d) ?? $d;
        }
        if (strlen($d) === 11 && ctype_digit($d)) {
            return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d) ?? $d;
        }

        return (string) $value;
    }

    /** CNPJ check digits, numeric or alphanumeric (each character weighs ord(c) - 48). */
    public static function isValidCnpj(string $cnpj): bool
    {
        $c = self::ni($cnpj);
        if (!preg_match('/^[0-9A-Z]{12}\d{2}$/', $c) || preg_match('/^(.)\1{13}$/', $c)) {
            return false;
        }
        foreach ([12, 13] as $len) {
            $sum = 0;
            $weight = $len - 7;
            for ($i = 0; $i < $len; $i++) {
                $sum += (ord($c[$i]) - 48) * $weight--;
                if ($weight < 2) {
                    $weight = 9;
                }
            }
            $check = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $c[$len] !== $check) {
                return false;
            }
        }

        return true;
    }

    /** Unit codes come as "012345" or "12345" depending on the endpoint. */
    public static function unitCode(mixed $value): string
    {
        return ltrim(trim((string) $value), '0');
    }

    /** First non-null value among spelling variants of a field. */
    public static function first(array $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                return $data[$key];
            }
        }

        return null;
    }

    public static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** PNCP date or datetime string to a SQL datetime (kept as received: no timezone shift). */
    public static function datetime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $value = substr(str_replace('T', ' ', $value), 0, 19);
        if (strlen($value) === 10) {
            $value .= ' 00:00:00';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) ? $value : null;
    }

    public static function date(mixed $value): ?string
    {
        $dt = self::datetime($value);

        return $dt === null ? null : substr($dt, 0, 10);
    }

    /** Date/datetime for screens, in the GLPI display format of the current user (empty when invalid). */
    public static function showDate(mixed $value): string
    {
        $dt = self::datetime($value);
        if ($dt === null) {
            return '';
        }

        return substr($dt, 11) === '00:00:00' ? \Html::convDate(substr($dt, 0, 10)) : \Html::convDateTime($dt);
    }

    /** dd/mm/yyyy for text stored in GLPI fields (comments), independent of any session. */
    public static function brDate(?string $value): string
    {
        $d = self::date($value);

        return $d === null ? '' : implode('/', array_reverse(explode('-', $d)));
    }

    /** "33/2026" from "00033/2026", "33/26", "Contrato 33/2026"; empty when there is no number/year. */
    public static function normalizeNumber(?string $value): string
    {
        if (!preg_match('/(\d+)\s*[\/\-.]\s*(\d{4}|\d{2})(?!\d)/', (string) $value, $m)) {
            return '';
        }
        $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];

        return (ltrim($m[1], '0') ?: '0') . '/' . $year;
    }

    public static function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }

    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * Slice [from, to] into consecutive windows of at most $days days.
     *
     * @return Generator<int, array{DateTimeImmutable, DateTimeImmutable}>
     */
    public static function windows(DateTimeImmutable $from, DateTimeImmutable $to, int $days): Generator
    {
        $current = $from;
        while ($current <= $to) {
            $end = $current->add(new DateInterval('P' . max(0, $days - 1) . 'D'));
            if ($end > $to) {
                $end = $to;
            }
            yield [$current, $end];
            $current = $end->add(new DateInterval('P1D'));
        }
    }

    /** Public portal page of a record, from its control number pieces. */
    public static function portalUrl(string $kind, string $cnpj, int $year, int $sequential, ?int $sub = null): string
    {
        return "https://pncp.gov.br/app/$kind/$cnpj/$year/$sequential" . ($sub !== null ? "/$sub" : '');
    }

    /** Short Portuguese words kept lowercase when an all-caps text is converted to sentence case. */
    private const STOPWORDS = ['A', 'O', 'AS', 'OS', 'E', 'É', 'DE', 'DA', 'DO', 'DAS', 'DOS', 'EM', 'NA', 'NO', 'NAS', 'NOS',
        'AO', 'AOS', 'À', 'ÀS', 'UM', 'UMA', 'POR', 'COM', 'SEM', 'PARA', 'PELA', 'PELO', 'VIA', 'OU', 'SOB', 'ATÉ', 'QUE',
        'SEU', 'SUA', 'TIPO', 'BEM', 'MAIS', 'ENTRE', 'SOBRE', 'ANO', 'MÊS', 'DIA', 'LOTE', 'ITEM'];

    /**
     * A short description of an object text (≤ $max chars): boilerplate openings
     * ("Contratação de empresa especializada para...") removed, cut at the first
     * clause separator, all-caps text turned into sentence case keeping acronyms.
     */
    public static function summary(?string $object, int $max = 60): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $object) ?? '');
        if ($s === '') {
            return '';
        }
        $openings = [
            '/^(o presente|este) (instrumento|termo|contrato|ajuste) tem (como|por) objeto(,)?( a| o)?\s*/iu',
            '/^(objeto:?\s*)/iu',
            '/^registro de pre[çc]os,?( para| visando)?( a| o| à| ao)?( eventual| futura| eventuais| futuras)*( contrata[çc][ãa]o| aquisi[çc][ãa]o)?( de| do| da)?\s*/iu',
            '/^(contrata[çc][ãa]o|contratar)( de| da| do)?( uma)?( empresa| pessoa jur[íi]dica| pessoa f[íi]sica| institui[çc][ãa]o| entidade| sociedade)?( especializada)?( para| em| na| no| visando| com vistas)?( a| o| à| ao)?\s*/iu',
            '/^(estabelecer|instituir|celebrar)( um| uma| o| a)?\s*/iu',
            '/^servi[çc]os? de\s+/iu',
        ];
        // Leftovers of a removed opening: "– SRP, para o fornecimento eventual de..."
        $leftovers = [
            '/^[\s,;:–\-]+/u',
            '/^(srp|sistema de registro de pre[çc]os)\b[\s,;:–\-]*/iu',
            '/^(para|visando)( a| o| à| ao)?( eventual| eventuais| futura| futuras)?\s+/iu',
        ];
        foreach ($openings as $re) {
            $s = preg_replace($re, '', $s, 1) ?? $s;
        }
        foreach ($leftovers as $re) {
            $s = preg_replace($re, '', $s, 1) ?? $s;
        }
        // First clause only.
        $s = preg_split('/\s*(?:[,;:(]| – | - | conforme | visando | com vistas | por meio | mediante | nos termos )/u', $s)[0] ?? $s;
        $s = trim($s, " .-–");
        if ($s === '') {
            $s = trim((string) $object);
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $s) ?? '';
        if ($letters !== '' && mb_strtoupper($letters) === $letters) {
            $words = explode(' ', $s);
            foreach ($words as $i => $w) {
                $bare = preg_replace('/[^\p{L}]/u', '', $w) ?? '';
                // Short all-caps tokens that are not common words are acronyms (IBGE, TRF, SUSE, TI).
                $isAcronym = $bare !== '' && mb_strlen($bare) <= 4 && !in_array($bare, self::STOPWORDS, true);
                $words[$i] = $isAcronym ? $w : mb_strtolower($w);
            }
            $s = implode(' ', $words);
        }
        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);

        if (mb_strlen($s) > $max) {
            $cut = mb_substr($s, 0, $max);
            $space = mb_strrpos($cut, ' ');
            $s = rtrim($space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,.;-') . '…';
        }

        return $s;
    }

    /** "Contrato 32/2025 – Suporte técnico mensal para o software Pergamum" */
    public static function title(?string $kind, ?string $number, int $year, ?string $object): string
    {
        $kind = trim(preg_replace('/\s*\(.*\)\s*/u', ' ', (string) $kind) ?? '');  // "Contrato (termo inicial)" → "Contrato"
        $kind = str_replace(' - ', ' ', $kind);                                       // "Pregão - Eletrônico" → "Pregão Eletrônico"
        if ($kind === 'Outros') {
            $kind = 'Instrumento';
        }
        $number = ltrim(trim((string) $number), '0') ?: trim((string) $number);
        $head = trim($kind . ' ' . $number . ($year ? "/$year" : ''));
        $summary = self::summary($object);

        return self::truncate($summary === '' ? $head : "$head – $summary", 255) ?? $head;
    }

    public static function money(?float $value): string
    {
        return $value === null ? '' : 'R$ ' . number_format($value, 2, ',', '.');
    }
}
