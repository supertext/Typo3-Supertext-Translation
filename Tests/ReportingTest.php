<?php

// Checks the Commando Center glue (needs `composer install`, no TYPO3 instance):
//   php Tests/ReportingTest.php
// Which reporter action runs when, what is stored, what is sent, the ping answers and the
// ping middleware. Never talks to the live Supertext API or the center: a fake transport
// answers both.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Supertext\Typo3Translation\Configuration\ExtensionSettings;
use Supertext\Typo3Translation\Configuration\ExtensionVersion;
use Supertext\Typo3Translation\Configuration\StatusField;
use Supertext\Typo3Translation\Middleware\PingMiddleware;
use Supertext\Typo3Translation\Reporting\Installation;
use Supertext\Typo3Translation\Reporting\Reporting;
use Supertext\Typo3Translation\Reporting\StateStorage;
use Supertext\Typo3Translation\Reporting\Typo3Reporting;
use Supertext\Typo3Translation\Sdk\Http\HttpRequest;
use Supertext\Typo3Translation\Sdk\Http\HttpResponse;
use Supertext\Typo3Translation\Sdk\Http\HttpTransport;
use Supertext\Typo3Translation\PluginHealth\KeyCheck;
use Supertext\Typo3Translation\PluginHealth\ReporterState;
use Supertext\Typo3Translation\Sdk\Supertext;
use Supertext\Typo3Translation\Sdk\V1\Protocol;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;

$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf("%s:\n  expected: %s\n  actual:   %s", $name, json_encode($expected), json_encode($actual));
    }
};

putenv('SUPERTEXT_VERSION_REPORTS');
putenv('SUPERTEXT_REPORT_URL');

final class MemoryStorage implements StateStorage
{
    /** @var array<string, string> */
    public array $values = [];
    public bool $broken = false;

    public function get(string $key): ?string
    {
        if ($this->broken) {
            throw new RuntimeException('database down');
        }
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        if ($this->broken) {
            throw new RuntimeException('database down');
        }
        $this->values[$key] = $value;
    }
}

/** Answers GET …/features (the key check) and POST <center> (the report). */
final class FakeTransport implements HttpTransport
{
    /** @var list<HttpRequest> */
    public array $requests = [];
    public int $featuresStatus = 200;
    public int $centerStatus = 202;

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if (str_starts_with($request->url, 'https://center.test/')) {
            return new HttpResponse($this->centerStatus, '{"ok":true}', ['content-type' => 'application/json']);
        }
        if (str_ends_with($request->url, '/features')) {
            return new HttpResponse($this->featuresStatus, $this->featuresStatus === 200 ? '[]' : '{"error_code":"UNAUTHORIZED"}', ['content-type' => 'application/json']);
        }
        return new HttpResponse(404, '{}');
    }

    /** @return list<array<string, mixed>> */
    public function reports(): array
    {
        $reports = [];
        foreach ($this->requests as $request) {
            if (str_starts_with($request->url, 'https://center.test/')) {
                $reports[] = json_decode((string)$request->body, true);
            }
        }
        return $reports;
    }

    public function keyChecks(): int
    {
        return count(array_filter($this->requests, static fn(HttpRequest $r): bool => str_ends_with($r->url, '/features')));
    }

    public function reset(): void
    {
        $this->requests = [];
    }
}

$clock = 1_760_000_000.0;
$storage = new MemoryStorage();
$transport = new FakeTransport();
$reporting = new Reporting($storage, $transport, 'https://center.test/api/v1/report', static function () use (&$clock): float {
    return $clock;
}, static function (): void {});
$installation = static fn(string $fingerprint = 'settings-1', bool $enabled = true, ?string $siteUrl = 'https://www.example.ch', string $typo3 = '14.3.9'): Installation => new Installation(
    reportsEnabled: $enabled,
    siteUrl: $siteUrl,
    callbackUrl: $siteUrl !== null ? $siteUrl . '/supertext/ping' : null,
    pluginVersion: '0.1.0',
    systemVersion: $typo3,
    apiBaseUrl: 'https://api.supertext.test/v1/',
    settingsFingerprint: $fingerprint,
);
$key = 'Supertext-Auth-Key secret-test-key-123';

// 1. First run: the configuration counts as saved -> key check + "installed".
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation(), $key);
$reports = $transport->reports();
$check('first run: event', $result?->event, 'installed');
$check('first run: sent', $result?->sent, true);
$check('first run: key checked once', $transport->keyChecks(), 1);
$check('first run: key ok', $result?->check?->status, KeyCheck::OK);
$check('first run: plugin name', $reports[0]['plugin'] ?? null, ['name' => 'Typo3-Supertext-Translation', 'version' => '0.1.0']);
$check('first run: system', $reports[0]['system'] ?? null, ['name' => 'TYPO3', 'version' => '14.3.9']);
$check('first run: site url', $reports[0]['site_url'] ?? null, 'https://www.example.ch');
$check('first run: callback', $reports[0]['callback'] ?? null, ['url' => 'https://www.example.ch/supertext/ping']);
$check('first run: key configured', $reports[0]['api_key_configured'] ?? null, true);
$check('first run: SDK version', $reports[0]['sdk_version'] ?? null, Supertext::SDK_VERSION);
$check('first run: key check User-Agent', str_contains($transport->requests[0]->headers['User-Agent'] ?? '', 'Typo3-Supertext-Translation/0.1.0'), true);
$check('first run: settings fingerprint stored', $storage->values[Reporting::SETTINGS_KEY] ?? null, 'settings-1');
$check('first run: state stored', ReporterState::from($storage->values[Reporting::STATE_KEY] ?? null)->reportedVersions, '0.1.0|14.3.9|' . PHP_VERSION);
foreach ($transport->requests as $request) {
    if (str_starts_with($request->url, 'https://center.test/') && str_contains((string)$request->body, 'secret-test-key')) {
        $failures[] = 'the API key was sent to the center';
    }
}
if (str_contains(implode('', $storage->values), 'secret-test-key')) {
    $failures[] = 'the API key was stored';
}

// 2. Same configuration, same versions: nothing to do, no request at all.
$transport->reset();
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation(), $key);
$check('quiet request: no event', $result?->event, null);
$check('quiet request: no HTTP', count($transport->requests), 0);

// 3. The configuration was saved again -> key check + "settings_saved".
$transport->reset();
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation('settings-2'), $key);
$check('saved: event', $result?->event, 'settings_saved');
$check('saved: key checked', $transport->keyChecks(), 1);

// 4. TYPO3 updated -> "upgraded" without a new key check.
$transport->reset();
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation('settings-2', typo3: '14.3.10'), $key);
$check('upgrade: event', $result?->event, 'upgraded');
$check('upgrade: no key check', $transport->keyChecks(), 0);

// 5. A translation got 401 -> key marked invalid without a request, "api_key_check" reported.
$transport->reset();
$result = $reporting->run(Reporting::ACTION_AUTHENTICATION_FAILED, $installation('settings-2', typo3: '14.3.10'), $key, 401);
$check('auth failed: event', $result?->event, 'api_key_check');
$check('auth failed: status', $result?->check?->status, KeyCheck::INVALID);
$check('auth failed: no key check request', $transport->keyChecks(), 0);
$check('auth failed: stored for the status page', $reporting->lastCheck()?->status, KeyCheck::INVALID);
$transport->reset();
$reporting->run(Reporting::ACTION_AUTHENTICATION_FAILED, $installation('settings-2', typo3: '14.3.10'), $key, 401);
$check('auth failed twice: reported once', count($transport->reports()), 0);

// 6. Reports off: the key is still checked after a save, nothing goes to the center.
$transport->reset();
$transport->featuresStatus = 403;
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation('settings-3', enabled: false), $key);
$check('off: key checked', $transport->keyChecks(), 1);
$check('off: key invalid', $result?->check?->status, KeyCheck::INVALID);
$check('off: nothing reported', count($transport->reports()), 0);
$transport->featuresStatus = 200;

// 7. No site URL known (CLI, relative site base): key check only.
$transport->reset();
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation('settings-4', siteUrl: null), $key);
$check('no site url: key checked', $transport->keyChecks(), 1);
$check('no site url: nothing reported', count($transport->reports()), 0);

// 8. The center is down: the failure is remembered, no new attempt for an hour.
$transport->reset();
$transport->centerStatus = 503;
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation('settings-5'), $key);
$check('center down: tried', $result?->event, 'settings_saved');
$check('center down: not sent', $result?->sent, false);
$transport->reset();
$transport->centerStatus = 202;
$reporting->run(Reporting::ACTION_REQUEST, $installation('settings-5', typo3: '14.4.0'), $key);
$check('center down: no retry within the hour', count($transport->reports()), 0);
$clock += 3601;
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation('settings-5', typo3: '14.4.0'), $key);
$check('center back after an hour: upgraded', $result?->event, 'upgraded');
$check('center back after an hour: sent', $result?->sent, true);

// 9. A missing key: "missing" without a request.
$transport->reset();
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation('settings-6'), '');
$check('missing key: status', $result?->check?->status, KeyCheck::MISSING);
$check('missing key: no key check request', $transport->keyChecks(), 0);
$check('missing key: reported as not configured', $transport->reports()[0]['api_key_configured'] ?? null, false);

// 10. Broken storage never throws.
$storage->broken = true;
$check('broken storage: run', $reporting->run(Reporting::ACTION_REQUEST, $installation(), $key), null);
$check('broken storage: last check', $reporting->lastCheck(), null);
$check('broken storage: settingsChanged', $reporting->settingsChanged($installation()), false);
$storage->broken = false;

// 10b. A state stored by the SDK 0.2 reporter is read as it is: no new "installed" report.
$storage = new MemoryStorage();
$transport = new FakeTransport();
$reporting = new Reporting($storage, $transport, 'https://center.test/api/v1/report', static function () use (&$clock): float {
    return $clock;
}, static function (): void {});
$storage->values[Reporting::SETTINGS_KEY] = 'settings-1';
$storage->values[Reporting::STATE_KEY] = (string)json_encode([
    'reportedVersions' => '0.1.0|14.3.9|' . PHP_VERSION,
    'reportedKeyStatus' => 'ok',
    'lastFailureAt' => null,
    'keyCheck' => ['status' => 'ok', 'checkedAt' => '2026-10-13T08:00:00Z', 'httpStatus' => 200, 'errorCode' => null, 'fingerprint' => Protocol::apiKeyFingerprint($key)],
], JSON_UNESCAPED_SLASHES);
$stored = $storage->values[Reporting::STATE_KEY];
$result = $reporting->run(Reporting::ACTION_REQUEST, $installation(), $key);
$check('SDK 0.2 state: nothing due', [$result?->event, $transport->requests], [null, []]);
// Plugin Health 0.2+ adds keyChecks and reportedKeyStatuses (null with one key); everything else is kept.
$check('SDK 0.2 state: same format', json_decode($storage->values[Reporting::STATE_KEY], true), json_decode($stored, true) + ['keyChecks' => null, 'reportedKeyStatuses' => null]);
$check('SDK 0.2 state: key check kept', $reporting->lastCheck()?->checkedAt, '2026-10-13T08:00:00Z');

// 10c. Send configuration to Supertext: an `extensions` report now, also within the hour after a failed report; nothing when off.
$storage = new MemoryStorage();
$transport = new FakeTransport();
$reporting = new Reporting($storage, $transport, 'https://center.test/api/v1/report', static function () use (&$clock): float {
    return $clock;
}, static function (): void {});
$transport->centerStatus = 503;
$reporting->run(Reporting::ACTION_REQUEST, $installation(), $key);
$transport->centerStatus = 202;
$transport->reset();
$extensions = [['name' => 'georgringer/news', 'version' => '12.3.0', 'active' => true, 'type' => 'extension']];
$languages = [['code' => 'main:en_US', 'supertext' => 'en-US'], ['code' => 'main:de_CH', 'supertext' => 'de-CH'], ['code' => 'main:en_IE', 'supertext' => null]];
$result = $reporting->sendConfiguration($installation(), $extensions, $languages);
$reports = $transport->reports();
$check('send configuration: event', [$result?->event, $result?->sent], ['extensions', true]);
$check('send configuration: extensions', $reports[0]['extensions'] ?? null, $extensions);
$check('send configuration: languages', $reports[0]['languages'] ?? null, $languages);
$transport->reset();
$check('send configuration off', (bool)$reporting->sendConfiguration($installation(enabled: false), $extensions, $languages)?->sent, false);
$check('send configuration off: nothing sent', $transport->reports(), []);

// 11. Ping answers (Plugin Health's pingResponse).
$ping = Reporting::ping('abcdefghijklmnop1234', true);
$check('ping ok: status', $ping->status, 200);
$check('ping ok: body', $ping->body, '{"ok":true,"plugin":"Typo3-Supertext-Translation","challenge":"abcdefghijklmnop1234"}');
$check('ping ok: no-store', $ping->headers['Cache-Control'] ?? null, 'no-store');
$check('ping bad challenge', Reporting::ping('<script>', true)->status, 400);
$check('ping missing challenge', Reporting::ping(null, true)->status, 400);
$check('ping while off', Reporting::ping('abcdefghijklmnop1234', false)->status, 404);

// 12. Callback URL: the installation URL of the request on the site's host, else the site's origin.
$check('callback from request', Typo3Reporting::callbackFor('https://www.example.ch/de', 'https://www.example.ch/cms/'), 'https://www.example.ch/cms/supertext/ping');
$check('callback other host', Typo3Reporting::callbackFor('https://www.example.ch/de', 'https://admin.example.net/'), 'https://www.example.ch/supertext/ping');
$check('callback cli', Typo3Reporting::callbackFor('https://www.example.ch:8443/de', null), 'https://www.example.ch:8443/supertext/ping');

// 13. The setting: on by default, SUPERTEXT_VERSION_REPORTS wins.
$settingsWith = static function (array $config): ExtensionSettings {
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['supertext_translation'] = $config;
    return new ExtensionSettings(new ExtensionConfiguration());
};
$check('setting default on', $settingsWith([])->areVersionReportsEnabled(), true);
$check('setting off', $settingsWith(['versionReports' => '0'])->areVersionReportsEnabled(), false);
putenv('SUPERTEXT_VERSION_REPORTS=0');
$check('env off wins', $settingsWith(['versionReports' => '1'])->areVersionReportsEnabled(), false);
$check('env decides', $settingsWith(['versionReports' => '1'])->isVersionReportsSetByEnvironment(), true);
putenv('SUPERTEXT_VERSION_REPORTS=on');
$check('env on wins', $settingsWith(['versionReports' => '0'])->areVersionReportsEnabled(), true);
putenv('SUPERTEXT_VERSION_REPORTS');
$check('env not set', $settingsWith([])->isVersionReportsSetByEnvironment(), false);
$check('fingerprint changes with the configuration', $settingsWith(['apiKey' => 'a'])->getConfigurationFingerprint() !== $settingsWith(['apiKey' => 'b'])->getConfigurationFingerprint(), true);
$check('fingerprint ignores the status field', $settingsWith(['apiKey' => 'a'])->getConfigurationFingerprint(), $settingsWith(['apiKey' => 'a', 'status' => ''])->getConfigurationFingerprint());

// 14. The ping middleware: GET /supertext/ping only, before TYPO3 resolves a page.
$next = new class implements RequestHandlerInterface {
    public bool $called = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;
        return new Response('php://temp', 418);
    }
};
$middleware = new PingMiddleware($settingsWith(['versionReports' => '1']));
$pingRequest = (new ServerRequest('https://www.example.ch/supertext/ping?challenge=abcdefghijklmnop1234', 'GET'))->withQueryParams(['challenge' => 'abcdefghijklmnop1234']);
$response = $middleware->process($pingRequest, $next);
$check('middleware: 200', $response->getStatusCode(), 200);
$check('middleware: content type', $response->getHeaderLine('Content-Type'), 'application/json');
$check('middleware: body', (string)$response->getBody(), '{"ok":true,"plugin":"Typo3-Supertext-Translation","challenge":"abcdefghijklmnop1234"}');
$check('middleware: not passed on', $next->called, false);
$response = $middleware->process(new ServerRequest('https://www.example.ch/some/page', 'GET'), $next);
$check('middleware: other paths pass', $response->getStatusCode(), 418);
$next->called = false;
$middleware->process(new ServerRequest('https://www.example.ch/supertext/ping', 'POST'), $next);
$check('middleware: POST passes', $next->called, true);
$off = new PingMiddleware($settingsWith(['versionReports' => '0']));
$check('middleware: 404 while off', $off->process($pingRequest, $next)->getStatusCode(), 404);

// 15. Key check messages link supertext.com (account) and supertext.com → Integrations → API (key), in a new tab.
$linked = StatusField::withLinks('Generate a new one at supertext.com → Integrations → API. No account? Create one at supertext.com.');
$check('links: API key page', substr_count($linked, 'href="https://www.supertext.com/en/integrations/api" target="_blank" rel="noopener"'), 1);
$check('links: account page', substr_count($linked, 'href="https://www.supertext.com/person/en/account/signin" target="_blank" rel="noopener"'), 1);

// 16. The plugin version comes from ext_emconf.php.
$EM_CONF = [];
$_EXTKEY = 'supertext_translation';
include __DIR__ . '/../ext_emconf.php';
$check('version from ext_emconf.php', ExtensionVersion::get(), $EM_CONF['supertext_translation']['version']);

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo $failures === [] ? "OK: version reports, key check and ping\n" : 'FAILED: ' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
