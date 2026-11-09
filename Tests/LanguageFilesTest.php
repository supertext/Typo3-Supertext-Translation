<?php

// Checks the interface translations (no TYPO3 needed):
//   php Tests/LanguageFilesTest.php
// Every label in locallang.xlf exists in de/fr/it with a target, the same
// placeholders and the same URLs, and every label the code uses exists.
declare(strict_types=1);

$root = dirname(__DIR__);
$dir = $root . '/Resources/Private/Language';
$failures = [];

/** @return array<string, array{source: string, target: ?string}> */
$read = static function (string $file): array {
    $xml = simplexml_load_file($file);
    $units = [];
    foreach ($xml->file->body->{'trans-unit'} as $unit) {
        $units[(string)$unit['id']] = ['source' => (string)$unit->source, 'target' => isset($unit->target) ? (string)$unit->target : null];
    }
    return $units;
};
$placeholders = static fn(string $s): array => preg_match_all('/%(?:\d+\$)?[sd]/', $s, $m) ? $m[0] : [];
$urls = static function (string $s): array {
    preg_match_all('#https?://[^\s,)]+#', $s, $m);
    $u = $m[0];
    sort($u);
    return $u;
};

$english = $read($dir . '/locallang.xlf');
foreach (['de', 'fr', 'it'] as $lang) {
    $file = "$dir/$lang.locallang.xlf";
    if (!is_file($file)) {
        $failures[] = "$lang.locallang.xlf is missing";
        continue;
    }
    $units = $read($file);
    foreach (array_diff_key($english, $units) as $id => $_) {
        $failures[] = "$lang: missing $id";
    }
    foreach (array_diff_key($units, $english) as $id => $_) {
        $failures[] = "$lang: $id is not in locallang.xlf";
    }
    foreach (array_intersect_key($units, $english) as $id => $unit) {
        $source = $english[$id]['source'];
        if ($unit['source'] !== $source) {
            $failures[] = "$lang: source of $id differs from locallang.xlf";
        }
        if (trim((string)$unit['target']) === '') {
            $failures[] = "$lang: $id has no target";
            continue;
        }
        if ($placeholders($unit['target']) !== $placeholders($source)) {
            $failures[] = "$lang: placeholders of $id differ";
        }
        if ($urls($unit['target']) !== $urls($source)) {
            $failures[] = "$lang: URLs of $id differ";
        }
        if (str_contains($source, 'Supertext') && !str_contains($unit['target'], 'Supertext')) {
            $failures[] = "$lang: $id lost the name Supertext";
        }
    }
}

// Every key the code and the extension configuration use exists.
$used = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/Classes', FilesystemIterator::SKIP_DOTS)) as $file) {
    preg_match_all("/'((?:settings|result|error|status|keycheck|siteLanguage|file)\\.[A-Za-z.]*[A-Za-z])'/", file_get_contents((string)$file), $m);
    $used = array_merge($used, $m[1]);
}
// Keys built at runtime: the key check results and the tones.
foreach (['ok', 'invalid', 'missing', 'unreachable', 'error'] as $status) {
    $used[] = 'keycheck.' . $status;
}
foreach (['default', 'more', 'less'] as $tone) {
    $used[] = 'status.tone.' . $tone;
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/Configuration', FilesystemIterator::SKIP_DOTS)) as $file) {
    preg_match_all("/\\\$ll \\. '([A-Za-z.]+)'|locallang\\.xlf:([A-Za-z.]+)/", file_get_contents((string)$file), $m);
    $used = array_merge($used, array_filter($m[1]), array_filter($m[2]));
}
preg_match_all('/locallang\.xlf:([A-Za-z.]+)/', file_get_contents($root . '/ext_conf_template.txt'), $m);
$used = array_merge($used, $m[1]);
foreach (array_unique($used) as $key) {
    if (!isset($english[$key])) {
        $failures[] = "label $key is used but not defined in locallang.xlf";
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? 'OK: ' . count($english) . " labels in en, de, fr, it\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
