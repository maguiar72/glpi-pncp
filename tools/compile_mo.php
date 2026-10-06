<?php

/**
 * PNCP plugin for GLPI — compiles locales/*.po into .mo files (no gettext
 * tools needed). Usage: php tools/compile_mo.php
 * License: GPLv3+ (see LICENSE).
 */

$root = dirname(__DIR__);

/** @return array<string, string> msgid (with \0 plural separator) => msgstr (\0 separated) */
function parse_po(string $file): array
{
    $entries = [];
    $current = [];
    $field = null;
    $flush = static function () use (&$entries, &$current) {
        if (isset($current['msgid'])) {
            $id = $current['msgid'] . (isset($current['msgid_plural']) ? "\0" . $current['msgid_plural'] : '');
            $str = isset($current['msgstr']) ? $current['msgstr'] : implode("\0", $current['msgstr_n'] ?? []);
            if ($str !== '' && trim($str, "\0") !== '' || $id === '') {
                $entries[$id] = $str;
            }
        }
        $current = [];
    };
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            if ($line === '') {
                $flush();
            }
            continue;
        }
        if (preg_match('/^(msgid|msgid_plural|msgstr)(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m)) {
            if ($m[1] === 'msgid' && isset($current['msgid'])) {
                $flush();
            }
            $value = stripcslashes($m[3]);
            if ($m[2] !== '') {
                $field = ['msgstr_n', (int) $m[2]];
                $current['msgstr_n'][(int) $m[2]] = $value;
            } else {
                $field = $m[1];
                $current[$m[1]] = $value;
            }
        } elseif (preg_match('/^"(.*)"$/', $line, $m) && $field !== null) {
            if (is_array($field)) {
                $current['msgstr_n'][$field[1]] .= stripcslashes($m[1]);
            } else {
                $current[$field] .= stripcslashes($m[1]);
            }
        }
    }
    $flush();

    return $entries;
}

function write_mo(array $entries, string $file): void
{
    ksort($entries, SORT_STRING);
    $n = count($entries);
    $ids = $strs = '';
    $idIndex = $strIndex = [];
    foreach ($entries as $id => $str) {
        $idIndex[] = [strlen($id), strlen($ids)];
        $ids .= $id . "\0";
        $strIndex[] = [strlen($str), strlen($strs)];
        $strs .= $str . "\0";
    }
    $header = 28;
    $idTable = $header;
    $strTable = $idTable + 8 * $n;
    $idsStart = $strTable + 8 * $n;
    $strsStart = $idsStart + strlen($ids);
    $out = pack('V7', 0x950412de, 0, $n, $idTable, $strTable, 0, $idsStart);
    foreach ($idIndex as [$len, $off]) {
        $out .= pack('VV', $len, $idsStart + $off);
    }
    foreach ($strIndex as [$len, $off]) {
        $out .= pack('VV', $len, $strsStart + $off);
    }
    file_put_contents($file, $out . $ids . $strs);
}

foreach (glob("$root/locales/*.po") as $po) {
    $entries = parse_po($po);
    write_mo($entries, substr($po, 0, -3) . '.mo');
    echo basename($po) . ': ' . (count($entries) - 1) . " translated strings\n";
}
