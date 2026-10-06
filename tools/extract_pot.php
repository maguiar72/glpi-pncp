<?php

/**
 * PNCP plugin for GLPI — extracts translatable strings (domain "pncp") from PHP
 * and Twig sources into locales/pncp.pot. Usage: php tools/extract_pot.php
 * License: GPLv3+ (see LICENSE).
 */

$root = dirname(__DIR__);
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $path = $f->getPathname();
    if (preg_match('#[/\\\\](vendor|node_modules|\.git|tools)[/\\\\]#', $path)) {
        continue;
    }
    if (preg_match('/\.(php|twig)$/', $path)) {
        $files[] = $path;
    }
}
sort($files);

$str = '\'((?:[^\'\\\\]|\\\\.)*)\'';
$patterns = [
    // __('text', 'pncp'), __s('text', 'pncp')
    'single' => "/\\b__s?\\(\\s*$str\\s*,\\s*'pncp'\\s*\\)/s",
    // _n('singular', 'plural', $n, 'pncp')
    'plural' => "/\\b_n\\(\\s*$str\\s*,\\s*$str\\s*,\\s*[^,]+,\\s*'pncp'\\s*\\)/s",
    // _x('context', 'text', 'pncp')
    'context' => "/\\b_sx?\\(\\s*$str\\s*,\\s*$str\\s*,\\s*'pncp'\\s*\\)|\\b_x\\(\\s*$str\\s*,\\s*$str\\s*,\\s*'pncp'\\s*\\)/s",
];

$entries = [];
$unescape = static fn(string $s) => stripcslashes($s);
foreach ($files as $file) {
    $code = file_get_contents($file);
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    foreach ($patterns as $kind => $re) {
        if (!preg_match_all($re, $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m as $match) {
            $line = substr_count(substr($code, 0, $match[0][1]), "\n") + 1;
            if ($kind === 'single') {
                $key = $unescape($match[1][0]);
                $entries[$key] ??= ['msgid' => $key, 'refs' => []];
            } elseif ($kind === 'plural') {
                $key = $unescape($match[1][0]);
                $entries[$key] ??= ['msgid' => $key, 'refs' => []];
                // A string used both alone and with a plural form is a plural entry.
                $entries[$key]['plural'] = $unescape($match[2][0]);
            } else {
                continue; // no context strings in this plugin yet
            }
            $entries[$key]['refs'][] = "$rel:$line";
        }
    }
}
ksort($entries, SORT_STRING | SORT_FLAG_CASE);

$q = static fn(string $s) => '"' . addcslashes($s, "\"\\\n\t") . '"';
$out = "# PNCP plugin for GLPI\n# This file is distributed under the same license as the plugin (GPLv3+).\n"
    . "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n\n";
foreach ($entries as $e) {
    $out .= '#: ' . implode(' ', array_unique($e['refs'])) . "\n";
    $out .= 'msgid ' . $q($e['msgid']) . "\n";
    if (isset($e['plural'])) {
        $out .= 'msgid_plural ' . $q($e['plural']) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
    } else {
        $out .= "msgstr \"\"\n\n";
    }
}
file_put_contents("$root/locales/pncp.pot", $out);
echo count($entries) . " strings written to locales/pncp.pot\n";
