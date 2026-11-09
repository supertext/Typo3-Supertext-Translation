<?php

// Checks SiteList's fallback for the install tool's failsafe mode, where TYPO3's SiteFinder
// isn't available and the extension configuration's Status block reads the sites from their
// config.yaml files (needs `composer install`, no TYPO3 instance):
//   php Tests/SiteListTest.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Log\NullLogger;
use Supertext\Typo3Translation\Service\LanguageResolver;
use Supertext\Typo3Translation\Site\SiteList;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};

// TYPO3's YAML loader only reads files inside the project: a throw-away project folder.
$project = sys_get_temp_dir() . '/supertext-sitelist-' . bin2hex(random_bytes(4));
Environment::initialize(new ApplicationContext('Testing'), true, true, $project, $project . '/public', $project . '/var', $project . '/config', $project . '/vendor/bin/typo3', 'UNIX');
$dir = $project . '/config/sites';
$write = static function (string $identifier, string $yaml) use ($dir): void {
    mkdir($dir . '/' . $identifier, 0777, true);
    file_put_contents($dir . '/' . $identifier . '/config.yaml', $yaml);
};
$write('camino', <<<'YAML'
rootPageId: 1
base: 'https://camino.example.com/'
languages:
  -
    languageId: 0
    title: English
    locale: en_US.UTF-8
    base: /
  -
    languageId: 1
    title: 'Deutsch (Schweiz)'
    locale: de-CH
    base: /de/
    supertext_code: de-CH
    supertext_politeness: more
  -
    languageId: 2
    title: 'Français (Suisse)'
    locale: fr_CH
    base: /fr/
YAML);
$write('no-root', "base: /\nlanguages: []\n");
mkdir($dir . '/empty-folder');

$sites = SiteList::fromFiles($dir, new YamlFileLoader(new NullLogger()));
$check('only sites with a root page', array_keys($sites), ['camino']);

$site = $sites['camino'];
$check('root page', $site->getRootPageId(), 1);
$check('base', (string)$site->getBase(), 'https://camino.example.com/');
$languages = array_values($site->getAllLanguages());
$check('languages', array_map(static fn($l) => $l->getTitle(), $languages), ['English', 'Deutsch (Schweiz)', 'Français (Suisse)']);
$check('default language', $site->getDefaultLanguage()->getLanguageId(), 0);
$check('locale without encoding', LanguageResolver::localeOf($languages[0]), 'en-US');
$check('mapping read from config.yaml', LanguageResolver::overrideOf($languages[1]), 'de-CH');
$check('politeness read from config.yaml', LanguageResolver::politenessOf($languages[1]), 'more');
$check('source code', LanguageResolver::sourceCodeOrNull(LanguageResolver::localeOf($languages[0]), LanguageResolver::overrideOf($languages[0])), 'en');
$check('target code by exact match', LanguageResolver::targetCodeOrNull(LanguageResolver::localeOf($languages[2]), LanguageResolver::overrideOf($languages[2])), 'fr-CH');

$check('missing sites folder', SiteList::fromFiles($dir . '/nope', new YamlFileLoader(new NullLogger())), []);

// clean up
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
};
$remove($project);

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? "OK: sites from config.yaml\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
