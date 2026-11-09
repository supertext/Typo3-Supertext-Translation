<?php

// Checks the glue between the extension and the Supertext SDK (needs `composer install`):
//   php Tests/SdkTest.php
// The SDK has its own tests for the protocol, HTML packing and the language list.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Supertext\Typo3Translation\Api\SupertextClientFactory;
use Supertext\Typo3Translation\Configuration\ExtensionSettings;
use Supertext\Typo3Translation\Configuration\SiteLanguageItems;
use Supertext\Typo3Translation\EventListener\ValidateSupertextCodes;
use Supertext\Typo3Translation\Localization\Labels;
use Supertext\Typo3Translation\Sdk\Http\HttpRequest;
use Supertext\Typo3Translation\Sdk\Http\HttpResponse;
use Supertext\Typo3Translation\Sdk\Http\HttpTransport;
use Supertext\Typo3Translation\Sdk\V1\Client;
use Supertext\Typo3Translation\Sdk\V1\Languages;
use Supertext\Typo3Translation\Sdk\V1\Segment;
use Supertext\Typo3Translation\Sdk\V1\SupertextException;
use Supertext\Typo3Translation\Service\LanguageResolver;
use Supertext\Typo3Translation\Upgrades\SupertextCodeUpgradeWizard;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};

// 1. Every SDK error code has a label, and the label exists in all four languages.
$labels = (new ReflectionClassConstant(Labels::class, 'ERROR_LABELS'))->getValue();
$codes = (new ReflectionClass(SupertextException::class))->getConstants(ReflectionClassConstant::IS_PUBLIC);
$english = file_get_contents(__DIR__ . '/../Resources/Private/Language/locallang.xlf');
foreach ($codes as $code) {
    if (!isset($labels[$code])) {
        $failures[] = "SDK error code $code has no label in Labels::ERROR_LABELS";
    } elseif (!str_contains($english, 'id="' . $labels[$code] . '"')) {
        $failures[] = "label {$labels[$code]} (for $code) is missing in locallang.xlf";
    }
}

// 2. TYPO3 locales, as LanguageResolver hands them to the SDK (encoding stripped): mapping, exact, default.
$strip = static fn(string $locale): string => LanguageResolver::stripEncoding($locale);
$target = static function (string $locale, string $override = ''): string {
    try {
        return LanguageResolver::targetCode($locale, $override);
    } catch (SupertextException $e) {
        return $e->errorCode . ':' . $e->targetLang;
    }
};
$check('target de_CH.UTF-8', $target($strip('de_CH.UTF-8')), 'de-CH');
$check('target fr_FR.UTF-8@euro', $target($strip('fr_FR.UTF-8@euro')), 'fr-FR');
$check('target bare de: the SDK default', $target($strip('de')), 'de-DE');
$check('target de_LI has no default', $target('de_LI'), 'unsupported_language:de_LI');
$check('target en_IE has no default', $target('en_IE'), 'unsupported_language:en_IE');
$check('target bare de mapped', $target('de', 'de-CH'), 'de-CH');
$check('target bare es (Supertext wants bare)', $target('es'), 'es');
$check('target es_ES: default es', $target('es_ES'), 'es');
$check('target nl_NL mapped to nl', $target('nl_NL', 'nl'), 'nl');
$check('target zh_CN: default zh-Hans', $target('zh_CN'), 'zh-Hans');
$check('target zh_TW: default zh-Hant', $target('zh_TW'), 'zh-Hant');
$check('default used for de', LanguageResolver::usesDefault('de'), true);
$check('no default for an exact match', LanguageResolver::usesDefault('de_CH'), false);
$check('no default with a mapping', LanguageResolver::usesDefault('de', 'de-CH'), false);
$check('mapping value sent as it is', $target('xx', 'xx-YY'), 'xx-YY');
$check('source en_US.UTF-8', LanguageResolver::sourceCode($strip('en_US.UTF-8')), 'en');
$check('source from a regional supertext_code', LanguageResolver::sourceCode('en_US', 'de-CH'), 'de');
$check('source unknown', LanguageResolver::sourceCodeOrNull('xx_XX'), null);
$check('one mapping, target de-CH', LanguageResolver::targetCode('de', 'de-CH'), 'de-CH');
$check('one mapping, source de', LanguageResolver::sourceCode('de', 'de-CH'), 'de');
$check('source en with an en-US mapping', LanguageResolver::sourceCode('en', 'en-US'), 'en');
$check('politeness offered for de-CH', Languages::supportsPoliteness('de-CH'), true);
$check('no politeness for en-US', Languages::supportsPoliteness('en-US'), false);
$check('the site config select lists every Supertext target', count(Languages::TARGETS) > 30, true);

// 2b. The site language select (SiteLanguageItems), the migration of old values and the save check.
$label = static fn(string $key, string ...$args): string => $key . ($args !== [] ? ':' . implode(',', $args) : '');
$items = SiteLanguageItems::items('de.UTF-8', '', 'en', $label);
$check('select: default option for de', $items[0], ['label' => 'siteLanguage.code.default:de-DE', 'value' => '']);
$check('select: every target', count($items), count(Languages::TARGETS) + 1);
$check('select: sorted by name, with the code', $items[1]['label'], Languages::targetOptions()[0]['name'] . ' (' . Languages::targetOptions()[0]['code'] . ')');
$check('select: no default for en_IE', SiteLanguageItems::items('en_IE', '', 'en', $label)[0]['label'], 'siteLanguage.code.choose');
$check('select: no default for an empty locale', SiteLanguageItems::items('', '', 'en', $label)[0]['label'], 'siteLanguage.code.choose');
$custom = SiteLanguageItems::items('de', 'xx-YY', 'en', $label);
$check('select: a custom YAML code stays selectable', end($custom), ['label' => 'siteLanguage.code.custom:xx-YY', 'value' => 'xx-YY']);
$check('select: a list code is not added twice', count(SiteLanguageItems::items('de', 'de-CH', 'en', $label)), count(Languages::TARGETS) + 1);
if (class_exists(Locale::class)) {
    $check('select: German names in German', SiteLanguageItems::displayName('de-CH', 'German (Switzerland)', 'de'), 'Deutsch (Schweiz)');
    $check('select: Swiss German dialects in German', SiteLanguageItems::displayName('gsw-u-sd-chzh', 'Swiss German (Zurich)', 'de'), 'Schweizerdeutsch (Zürich)');
    $check('select: English names from the SDK', SiteLanguageItems::displayName('gsw-u-sd-chzh', 'Swiss German (Zurich)', 'en'), 'Swiss German (Zurich)');
}
$check('tone offered for de (default de-DE)', SiteLanguageItems::politenessOffered('de', ''), true);
$check('no tone for en (default en-US)', SiteLanguageItems::politenessOffered('en_US', ''), false);
$check('tone shown while a language has no Supertext language', SiteLanguageItems::politenessOffered('en_IE', ''), true);
$check('migrate: a list code stays', LanguageResolver::migratedCode('de-CH'), null);
$check('migrate: empty stays', LanguageResolver::migratedCode(''), null);
$check('migrate: case and _', LanguageResolver::migratedCode('de_ch'), ['code' => 'de-CH', 'politeness' => '']);
$check('migrate: bare de as 0.1 sent it', LanguageResolver::migratedCode('de'), ['code' => 'de-DE', 'politeness' => '']);
$check('migrate: es_ES', LanguageResolver::migratedCode('es_ES'), ['code' => 'es', 'politeness' => '']);
$check('migrate: formal variant', LanguageResolver::migratedCode('de_DE_formal'), ['code' => 'de-DE', 'politeness' => 'more']);
$check('migrate: a set tone wins', LanguageResolver::migratedCode('de_DE_formal', 'less'), ['code' => 'de-DE', 'politeness' => 'less']);
$check('migrate: unknown stays', LanguageResolver::migratedCode('xx-YY'), null);
$site = ['languages' => [['languageId' => 0, 'locale' => 'en_US'], ['languageId' => 1, 'supertext_code' => 'de_ch'], ['languageId' => 2, 'supertext_code' => 'fr-CH']]];
$migrated = SupertextCodeUpgradeWizard::migrate($site);
$check('wizard: migrates the old value', $migrated['languages'][1]['supertext_code'] ?? null, 'de-CH');
$check('wizard: leaves the rest', ($migrated['languages'][2] ?? null) === $site['languages'][2] && ($migrated['languages'][0] ?? null) === $site['languages'][0], true);
$check('wizard: nothing to do', SupertextCodeUpgradeWizard::migrate($migrated ?? []), null);
$posted = ['languages' => [['supertext_code' => 'de-ch'], ['supertext_code' => 'xx-YY'], ['supertext_code' => 'zz'], ['supertext_code' => '', 'supertext_politeness' => 'very']]];
$validated = ValidateSupertextCodes::validate($posted, ['xx-YY']);
$check('save: spelled as in the list', $validated['languages'][0]['supertext_code'], 'de-CH');
$check('save: a stored custom code stays', $validated['languages'][1]['supertext_code'], 'xx-YY');
$check('save: an unknown new code is dropped', $validated['languages'][2]['supertext_code'], '');
$check('save: an invalid tone is dropped', $validated['languages'][3]['supertext_politeness'], '');
$tones = ValidateSupertextCodes::validate(['languages' => [
    ['locale' => 'de_CH.UTF-8', 'supertext_code' => 'en-US', 'supertext_politeness' => 'more'],
    ['locale' => 'de_CH.UTF-8', 'supertext_code' => '', 'supertext_politeness' => 'more'],
]], []);
$check('save: a tone the language does not offer is dropped', $tones['languages'][0]['supertext_politeness'], '');
$check('save: a tone the language offers stays', $tones['languages'][1]['supertext_politeness'], 'more');

// 3. translateTexts round trip with a fake transport that "translates" by putting » before every text node.
$transport = new class implements HttpTransport {
    public string $uploaded = '';
    public array $fields = [];

    public function send(HttpRequest $request): HttpResponse
    {
        if ($request->method === 'POST') {
            $this->uploaded = (string)$request->body;
            preg_match_all('/name="(target_lang|source_lang|politeness)"\r\n\r\n([^\r]*)/', $this->uploaded, $m);
            $this->fields = array_combine($m[1], $m[2]);
            return new HttpResponse(200, '{"file_id":"f1"}');
        }
        if (str_ends_with($request->url, '/status')) {
            return new HttpResponse(200, '{"status":"done"}');
        }
        if (str_ends_with($request->url, '/translation')) {
            preg_match('/<body>.*<\/body>/s', $this->uploaded, $m);
            $body = preg_replace_callback('/>([^<]+)</', static fn(array $t): string => '>»' . $t[1] . '<', $m[0]);
            return new HttpResponse(200, '<html>' . $body . '</html>');
        }
        return new HttpResponse(204);
    }
};
$client = new Client('Supertext-Auth-Key test', baseUrl: 'https://api.supertext.com/v1/', transport: $transport, sleep: static function (): void {});
$result = $client->translateTexts([
    3 => new Segment("Fish & Chips\nfor two", false),
    7 => new Segment('<p>We ship <strong>Swiss chocolate</strong>.</p>', true),
    9 => new Segment('  ', false),
], 'de-CH', 'en', 'more');
$check('plain text segment', $result[3] ?? null, "»Fish & Chips\n»for two");
$check('html segment', $result[7] ?? null, '<p>»We ship <strong>»Swiss chocolate</strong>».</p>');
$check('blank segment unchanged', $result[9] ?? null, '  ');
$check('keys kept in order', array_keys($result), [3, 7, 9]);
$check('target sent as given', $transport->fields['target_lang'] ?? null, 'de-CH');
$check('source sent bare', $transport->fields['source_lang'] ?? null, 'en');
$check('politeness sent', $transport->fields['politeness'] ?? null, 'more');

// 4. A language Supertext doesn't know at all (HTTP 404 INVALID_LANGUAGE_PAIR) gets the language pair label.
$unknown = new Client('test', transport: new class implements HttpTransport {
    public function send(HttpRequest $request): HttpResponse
    {
        return new HttpResponse(404, '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx-YY"}', ['content-type' => 'application/json']);
    }
}, sleep: static function (): void {});
try {
    $unknown->submit('<p data-st-id="0">Hi</p>', 'xx-YY', 'en');
    $failures[] = 'unknown language: expected an error';
} catch (SupertextException $e) {
    $check('unknown language: error code', $e->errorCode, SupertextException::INVALID_LANGUAGE_PAIR);
    $check('unknown language: label', $labels[$e->errorCode] ?? null, 'error.languagePair');
}

// 5. The SDK's unsupported_language / unsupported_feature errors have their own labels.
$check('unsupported_language label', $labels[SupertextException::UNSUPPORTED_LANGUAGE] ?? null, 'error.unsupportedLanguage');
$check('unsupported_feature label', $labels[SupertextException::UNSUPPORTED_FEATURE] ?? null, 'error.unsupportedFeature');

// 6. Translation review: a site language with supertext_review goes through the review service
//    (upload, status, download and delete), others directly; reviewUrl '' turns it off.
$recorder = new class implements HttpTransport {
    /** @var list<string> */
    public array $calls = [];

    public function send(HttpRequest $request): HttpResponse
    {
        $this->calls[] = $request->method . ' ' . $request->url;
        if ($request->method === 'POST') {
            return new HttpResponse(200, '{"file_id":"f1"}');
        }
        if (str_ends_with($request->url, '/status')) {
            return new HttpResponse(200, '{"status":"done"}');
        }
        if (str_ends_with($request->url, '/translation')) {
            return new HttpResponse(200, '<html><body><div data-st-id="0">Hallo</div></body></html>');
        }
        return new HttpResponse(204);
    }
};
$factoryWith = static function (array $config): SupertextClientFactory {
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['supertext_translation'] = $config;
    return new SupertextClientFactory(new ExtensionSettings(new ExtensionConfiguration()), new RequestFactory(new GuzzleClientFactory()));
};
$hosts = static function (array $calls): array {
    $byHost = [];
    foreach ($calls as $call) {
        [$method, $url] = explode(' ', $call, 2);
        $byHost[(string)parse_url($url, PHP_URL_HOST)][] = $method;
    }
    return $byHost;
};
$factory = $factoryWith(['apiKey' => 'test-key', 'endpoint' => 'https://api.test/v1', 'reviewUrl' => 'https://review.test/v1']);
$factory->createWith(true, $recorder)->translateTexts([new Segment('Hello', false)], 'de-CH', 'en');
$check('review: upload, status, download and delete through the review service', $hosts($recorder->calls), ['review.test' => ['POST', 'GET', 'GET', 'DELETE']]);
$check('review: the review service URL', str_starts_with($recorder->calls[0], 'POST https://review.test/v1/'), true);
$recorder->calls = [];
$factory->createWith(false, $recorder)->translateTexts([new Segment('Hello', false)], 'fr-CH', 'en');
$check('review off: directly to Supertext', $hosts($recorder->calls), ['api.test' => ['POST', 'GET', 'GET', 'DELETE']]);
$check('review: default is Supertext\'s review service', $factoryWith([])->baseUrl(true), SupertextClientFactory::REVIEW_URL);
$check('review: default stays the API for other languages', $factoryWith([])->baseUrl(false), 'https://api.supertext.com/v1/');
$check('review: empty reviewUrl turns it off', $factoryWith(['reviewUrl' => ''])->baseUrl(true), 'https://api.supertext.com/v1/');
$check('review: the API URL as reviewUrl means no review client', $factoryWith(['reviewUrl' => 'https://api.supertext.com/v1'])->baseUrl(true), 'https://api.supertext.com/v1/');
putenv('SUPERTEXT_REVIEW_URL=https://other-review.test/v1/');
$check('review: SUPERTEXT_REVIEW_URL wins', $factoryWith(['reviewUrl' => ''])->baseUrl(true), 'https://other-review.test/v1/');
putenv('SUPERTEXT_REVIEW_URL');
$check('review: User-Agent with the version', (bool)preg_match('#^TYPO3-Supertext-Translation/\S+$#', SupertextClientFactory::userAgent()), true);
$base = new Uri('https://www.example.ch/');
$check('review: on for a target language', LanguageResolver::reviewOf(new SiteLanguage(1, 'de_CH.UTF-8', $base, ['supertext_review' => 1])), true);
$check('review: off by default', LanguageResolver::reviewOf(new SiteLanguage(1, 'de_CH.UTF-8', $base, [])), false);
$check('review: never for the default language', LanguageResolver::reviewOf(new SiteLanguage(0, 'en_US.UTF-8', $base, ['supertext_review' => 1])), false);
$check('review: the setting has a default in ext_conf_template.txt', str_contains((string)file_get_contents(__DIR__ . '/../ext_conf_template.txt'), 'reviewUrl = ' . SupertextClientFactory::REVIEW_URL), true);

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? "OK: extension and Supertext SDK fit together\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
