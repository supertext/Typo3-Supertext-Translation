<?php

// Checks the export/import glue (TranslationFileGlue) with a fake store (needs `composer install`):
//   php Tests/TranslationFilesTest.php
// The SDK has its own tests for the XLIFF and HTML formats.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Supertext\Typo3Translation\Files\FileItem;
use Supertext\Typo3Translation\Files\FileLanguage;
use Supertext\Typo3Translation\Files\TranslationFileGlue;
use Supertext\Typo3Translation\Files\TranslationFileGlueError;
use Supertext\Typo3Translation\Files\TranslationFileStore;
use Supertext\Typo3Translation\Sdk\Files\FileJob;
use Supertext\Typo3Translation\Sdk\Files\FileSegment;
use Supertext\Typo3Translation\Sdk\Files\TranslationFileError;
use Supertext\Typo3Translation\Sdk\Files\TranslationFiles;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};
$throws = static function (callable $fn): string {
    try {
        $fn();
        return 'no error';
    } catch (TranslationFileGlueError|TranslationFileError $e) {
        return $e->errorCode;
    } catch (\InvalidArgumentException) {
        return 'invalid_argument';
    }
};

final class FakeStore implements TranslationFileStore
{
    /** @var array<int, FileItem> */
    public array $items = [];
    /** @var array<int, FileLanguage> */
    public array $languages = [];
    /** @var list<array{int, int, array<string, string>}> */
    public array $written = [];

    public function item(int $page): ?FileItem
    {
        return $this->items[$page] ?? null;
    }

    public function language(int $page, int $language): ?FileLanguage
    {
        return $this->languages[$language] ?? null;
    }

    public function write(int $page, int $language, array $texts): array
    {
        $this->written[] = [$page, $language, $texts];
        return [];
    }
}

$segments = [
    new FileSegment('pages:12:title', 'Welcome'),
    new FileSegment('tt_content:7:header', 'Our offer'),
    new FileSegment('tt_content:7:bodytext', '<p>Fresh <b>bread</b> every day.</p>', true),
];
$store = new FakeStore();
$store->items[12] = new FileItem(12, 'Home', $segments);
// A mapped language, one with an exact locale, one without a Supertext code (en_IE).
$store->languages[1] = new FileLanguage(1, 'Deutsch (Schweiz)', 'en_US', '', 'de_CH', 'de-CH');
$store->languages[2] = new FileLanguage(2, 'Français', 'en_US', '', 'fr_CH', '');
$store->languages[3] = new FileLanguage(3, 'English (Ireland)', 'en_US', '', 'en_IE', '');
$glue = new TranslationFileGlue($store);

// 1. Reference format round trip; only this extension's references.
$check('reference', TranslationFileGlue::reference(12, 1), 'page:12:1');
$check('parse', TranslationFileGlue::parseReference('page:12:1'), ['page' => 12, 'language' => 1]);
foreach (['page:12', 'page:0:1', 'page:12:0', 'entry:12:1', 'page:12:1:x', 'page:-1:1', ''] as $bad) {
    $check("parse $bad", TranslationFileGlue::parseReference($bad), null);
}
$check('file name one target', TranslationFileGlue::fileName(12, ['de-CH'], 'xliff'), 'typo3-12-de-CH.xlf');
$check('file name several', TranslationFileGlue::fileName(12, ['de-CH', 'fr-CH'], 'xliff'), 'typo3-12.xlf');
$check('file name html', TranslationFileGlue::fileName(12, ['de-CH'], 'html'), 'typo3-12-de-CH.html');

// 2. Export: the collector's keys, the mapping, the fallback for unmapped languages.
$file = $glue->export(12, [1, 2, 3], 'xliff');
$check('export name', $file->fileName, 'typo3-12.xlf');
$check('export content type', $file->contentType, 'application/x-xliff+xml');
$jobs = TranslationFiles::read($file->content);
$check('export jobs', array_map(static fn($j) => [$j->plugin, $j->reference, $j->sourceLanguage, $j->targetLanguage], $jobs), [
    ['Typo3-Supertext-Translation', 'page:12:1', 'en', 'de-CH'],
    ['Typo3-Supertext-Translation', 'page:12:2', 'en', 'fr-CH'],
    ['Typo3-Supertext-Translation', 'page:12:3', 'en', 'en-IE'],
]);
$check('export keys', array_map(static fn($u) => $u->key, $jobs[0]->units), ['pages:12:title', 'tt_content:7:header', 'tt_content:7:bodytext']);
$html = $glue->export(12, [1], 'html');
$check('html name', [$html->fileName, $html->contentType], ['typo3-12-de-CH.html', 'text/html; charset=utf-8']);
$check('html two languages', $throws(fn() => $glue->export(12, [1, 2], 'html')), 'invalid_argument');
$check('unknown format', $throws(fn() => $glue->export(12, [1], 'po')), 'invalid_argument');
$check('export missing page', $throws(fn() => $glue->export(99, [1], 'xliff')), TranslationFileGlueError::NOT_FOUND);
$check('export missing language', $throws(fn() => $glue->export(12, [9], 'xliff')), TranslationFileGlueError::NO_LANGUAGE);
$store->items[13] = new FileItem(13, 'Empty', []);
$check('nothing to translate', $throws(fn() => $glue->export(13, [1], 'xliff')), TranslationFileGlueError::NOTHING_TO_TRANSLATE);
$check('nothing written by exports', $store->written, []);

// 3. Import: a translated XLIFF (the CAT tool fills <target>).
$translate = static function (string $xliff, array $targets): string {
    foreach ($targets as $id => $target) {
        $xliff = (string)preg_replace('#(<trans-unit id="' . preg_quote($id, '#') . '"[^>]*>\s*<source>.*?</source>)#s', '$1<target>' . $target . '</target>', $xliff, 1);
    }
    return $xliff;
};
$single = $glue->export(12, [1], 'xliff')->content;
$translated = $translate($single, [
    'pages:12:title' => 'Willkommen',
    'tt_content:7:bodytext' => '<bpt id="1">&lt;p&gt;</bpt>Frisches <bpt id="2">&lt;b&gt;</bpt>Brot<ept id="2">&lt;/b&gt;</ept> jeden Tag.<ept id="1">&lt;/p&gt;</ept>',
]);
// The source of the header changed since the export; the body's translation is fine; title ok.
$store->items[12] = new FileItem(12, 'Home', [
    new FileSegment('pages:12:title', 'Welcome'),
    new FileSegment('tt_content:7:header', 'Our new offer'),
    new FileSegment('tt_content:7:bodytext', '<p>Fresh <b>bread</b> every day.</p>', true),
    new FileSegment('tt_content:8:header', 'Added later'),
]);
$results = $glue->import($translated, false);
$check('import jobs', count($results), 1);
$r = $results[0];
$check('import counts', [$r->error, $r->page, $r->languageTitle, $r->imported, $r->sourceChanged, $r->untranslated, $r->invalid, $r->missing], [null, 12, 'Deutsch (Schweiz)', 2, 0, 1, 0, 1]);
$check('import written', $store->written, [[12, 1, ['pages:12:title' => 'Willkommen', 'tt_content:7:bodytext' => '<p>Frisches <b>Brot</b> jeden Tag.</p>']]]);

// source_changed only with the option.
$store->written = [];
$changed = $translate($single, ['pages:12:title' => 'Willkommen', 'tt_content:7:header' => 'Unser Angebot']);
$r = $glue->import($changed, false)[0];
$check('changed skipped', [$r->imported, $r->sourceChanged], [1, 1]);
$check('changed not written', $store->written[0][2], ['pages:12:title' => 'Willkommen']);
$store->written = [];
$r = $glue->import($changed, true)[0];
$check('changed included', [$r->imported, $r->sourceChanged], [2, 0]);
$check('changed written', $store->written[0][2], ['pages:12:title' => 'Willkommen', 'tt_content:7:header' => 'Unser Angebot']);

// Invalid markup isn't written.
$store->written = [];
$bad = $translate($single, ['tt_content:7:bodytext' => 'Frisches <ph id="9"/>Brot']);
$r = $glue->import($bad, false)[0];
$check('invalid markup', [$r->imported, $r->invalid, $r->untranslated], [0, 1, 2]);
$check('nothing written for invalid', $store->written, []);

// 4. Jobs of other systems, unknown references, missing pages and languages.
$foreign = TranslationFiles::exportXliff([
    new FileJob('Contao-Supertext-Translation', 'page:12:1', 'en', 'de-CH', $segments),
    new FileJob(TranslationFileGlue::PLUGIN, 'entry:12', 'en', 'de-CH', $segments),
    new FileJob(TranslationFileGlue::PLUGIN, 'page:99:1', 'en', 'de-CH', $segments),
    new FileJob(TranslationFileGlue::PLUGIN, 'page:12:9', 'en', 'it-CH', $segments),
]);
$results = $glue->import($foreign, true);
$check('job errors', array_map(static fn($j) => [$j->error?->errorCode, $j->error?->argument], $results), [
    [TranslationFileGlueError::OTHER_SYSTEM, 'Contao-Supertext-Translation'],
    [TranslationFileGlueError::NOT_FOUND, ''],
    [TranslationFileGlueError::NOT_FOUND, ''],
    [TranslationFileGlueError::NO_LANGUAGE, 'it-CH'],
]);
$check('nothing written for errors', $store->written, []);

// 5. HTML imported unchanged writes the source back; the SDK's file errors come through.
$store->items[12] = new FileItem(12, 'Home', $segments);
$store->written = [];
$r = $glue->import($glue->export(12, [2], 'html')->content, false)[0];
$check('html unchanged', [$r->imported, $store->written[0][1], $store->written[0][2]['pages:12:title']], [3, 2, 'Welcome']);
$check('invalid file', $throws(fn() => $glue->import('hello', false)), TranslationFileError::INVALID_FILE);
$check('missing metadata', $throws(fn() => $glue->import('<html><head></head><body><p data-st-id="a">x</p></body></html>', false)), TranslationFileError::MISSING_METADATA);

// 6. Files over 10 MB are refused before they are read.
$big = tempnam(sys_get_temp_dir(), 'st');
$handle = fopen($big, 'wb');
ftruncate($handle, TranslationFileGlue::MAX_UPLOAD_BYTES + 1);
fclose($handle);
$check('too large file', $throws(fn() => $glue->importFile($big, false)), TranslationFileGlueError::TOO_LARGE);
unlink($big);
$check('too large content', $throws(fn() => $glue->import(str_repeat(' ', TranslationFileGlue::MAX_UPLOAD_BYTES + 1), false)), TranslationFileGlueError::TOO_LARGE);

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? "OK: export and import of translation files\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
