<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Reporting;

use Supertext\Typo3Translation\PluginHealth\CallableKeyChecker;
use Supertext\Typo3Translation\PluginHealth\InstallationReporter;
use Supertext\Typo3Translation\PluginHealth\KeyCheck;
use Supertext\Typo3Translation\PluginHealth\PingResponse;
use Supertext\Typo3Translation\PluginHealth\ReporterResult;
use Supertext\Typo3Translation\PluginHealth\ReporterState;
use Supertext\Typo3Translation\Sdk\Http\HttpTransport;
use Supertext\Typo3Translation\Sdk\Supertext;
use Supertext\Typo3Translation\Sdk\V1\Client;
use Supertext\Typo3Translation\Sdk\V1\Protocol;

/**
 * The extension's side of the Supertext Commando Center (version reports, the automatic
 * API key check and the ping endpoint; Commando-Center-Supertext-Translation ->
 * docs/DEVELOPER.md -> Report protocol). The protocol is Supertext Plugin Health's
 * InstallationReporter (bundled in lib/supertext-plugin-health), the key check is the SDK's
 * Client::checkApiKey(); this class only wires them together, picks the action and keeps the
 * state. No TYPO3 classes, so it is
 * unit-tested on its own (Tests/ReportingTest.php); Typo3Reporting feeds it.
 *
 * TYPO3 has no event for "extension configuration saved", so a save is noticed by a changed
 * fingerprint of the configuration: the first back-end request that runs the extension after
 * a save (opening the extension configuration again, a translation, the CLI command) runs
 * afterSettingsSaved() instead of onRequest().
 *
 * Nothing here ever throws: reporting must never break a save or a translation.
 */
final class Reporting
{
    /** The GitHub repository name, sent as plugin.name and in the ping answer. */
    public const PLUGIN_NAME = 'Typo3-Supertext-Translation';
    public const SYSTEM_NAME = 'TYPO3';
    /** Path of the ping endpoint below the installation's root URL (PingMiddleware). */
    public const PING_PATH = 'supertext/ping';

    public const STATE_KEY = 'reporter';
    public const SETTINGS_KEY = 'settingsFingerprint';

    public const ACTION_REQUEST = 'request';
    public const ACTION_AUTHENTICATION_FAILED = 'authenticationFailed';

    /** @var callable(): float|null */
    private $now;

    /**
     * @param HttpTransport|null       $transport HTTP for the key check and the report (TYPO3's client)
     * @param string|null              $reportUrl default SUPERTEXT_REPORT_URL, else Plugin Health's default (tests)
     * @param (callable(): float)|null $now       wall clock (tests)
     * @param (callable(float): void)|null $sleep 429 waits of the key check (tests)
     */
    public function __construct(
        private readonly StateStorage $storage,
        private readonly ?HttpTransport $transport = null,
        private readonly ?string $reportUrl = null,
        ?callable $now = null,
        private $sleep = null,
    ) {
        $this->now = $now;
    }

    public static function ping(?string $challenge, bool $enabled): PingResponse
    {
        return InstallationReporter::pingResponse($challenge, self::PLUGIN_NAME, $enabled);
    }

    /** @param (callable(): list<array{code: string, supertext: ?string}>)|null $languages the language mapping (sendConfiguration only) */
    public function reporter(Installation $installation, ?callable $languages = null): InstallationReporter
    {
        $siteUrl = $installation->siteUrl;
        return new InstallationReporter(
            // Without a site URL there is nothing to report; the key is still checked.
            enabled: $installation->reportsEnabled && $siteUrl !== null && $siteUrl !== '',
            siteUrl: $siteUrl ?? '',
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $installation->pluginVersion,
            systemName: self::SYSTEM_NAME,
            keyChecker: new CallableKeyChecker(
                fn(#[\SensitiveParameter] string $key) => $this->client($key, $installation)->checkApiKey(),
                [Protocol::class, 'apiKeyFingerprint'],
            ),
            systemVersion: $installation->systemVersion,
            sdkVersion: Supertext::SDK_VERSION,
            callbackUrl: $installation->callbackUrl,
            reportUrl: $this->reportUrl,
            transport: $this->transport !== null ? new PluginHealthTransport($this->transport) : null,
            now: $this->now,
            languages: $languages,
        );
    }

    /** The SDK client for the key check: the configured endpoint, User-Agent "<plugin>/<version>". */
    private function client(#[\SensitiveParameter] string $apiKey, Installation $installation): Client
    {
        return new Client(
            $apiKey,
            baseUrl: $installation->apiBaseUrl,
            userAgent: self::PLUGIN_NAME . '/' . $installation->pluginVersion,
            transport: $this->transport,
            sleep: $this->sleep,
            now: $this->now,
        );
    }

    /** Whether the configuration changed since the last afterSettingsSaved() (or nothing ran yet). */
    public function settingsChanged(Installation $installation): bool
    {
        try {
            return $this->storage->get(self::SETTINGS_KEY) !== $installation->settingsFingerprint;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Runs one reporter action with the stored state and stores the new state. ACTION_REQUEST
     * becomes afterSettingsSaved() when the configuration was saved since the last run.
     */
    public function run(string $action, Installation $installation, #[\SensitiveParameter] string $apiKey, int $httpStatus = 401): ?ReporterResult
    {
        try {
            $state = ReporterState::from($this->storage->get(self::STATE_KEY));
            $reporter = $this->reporter($installation);
            $saved = false;
            if ($action === self::ACTION_AUTHENTICATION_FAILED) {
                $result = $reporter->authenticationFailed($state, $apiKey, $httpStatus);
            } elseif ($this->settingsChanged($installation)) {
                $result = $reporter->afterSettingsSaved($state, $apiKey);
                $saved = true;
            } else {
                $result = $reporter->onRequest($state, $apiKey);
            }
            $this->storage->set(self::STATE_KEY, (string)json_encode($result->state, JSON_UNESCAPED_SLASHES));
            if ($saved) {
                $this->storage->set(self::SETTINGS_KEY, $installation->settingsFingerprint);
            }
            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * "Send configuration to Supertext": one `extensions` report with the installed extensions and the
     * language mapping, sent now (also within the hour after a failed report). Nothing with reports off.
     *
     * @param list<array{name: string, version?: ?string, active?: ?bool, type?: ?string}> $extensions
     * @param list<array{code: string, supertext: ?string}>                                $languages
     */
    public function sendConfiguration(Installation $installation, array $extensions, array $languages): ?ReporterResult
    {
        try {
            $result = $this->reporter($installation, static fn(): array => $languages)
                ->sendExtensions(ReporterState::from($this->storage->get(self::STATE_KEY)), $extensions);
            $this->storage->set(self::STATE_KEY, (string)json_encode($result->state, JSON_UNESCAPED_SLASHES));
            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The latest stored key check (for the status overview). */
    public function lastCheck(): ?KeyCheck
    {
        try {
            return ReporterState::from($this->storage->get(self::STATE_KEY))->keyCheck;
        } catch (\Throwable) {
            return null;
        }
    }
}
