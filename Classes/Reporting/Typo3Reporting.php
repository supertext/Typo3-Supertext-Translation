<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Reporting;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Supertext\Typo3Translation\Api\SupertextClientFactory;
use Supertext\Typo3Translation\Configuration\ExtensionSettings;
use Supertext\Typo3Translation\Configuration\ExtensionVersion;
use Supertext\Typo3Translation\PluginHealth\KeyCheck;
use Supertext\Typo3Translation\PluginHealth\PingResponse;
use Supertext\Typo3Translation\PluginHealth\ReporterResult;
use Supertext\Typo3Translation\Site\SiteList;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Registry;

/**
 * Feeds Reporting with what TYPO3 knows: the site URL (the first site with an absolute
 * base, else the URL of the current request), TYPO3's version, the ping URL, the
 * extension configuration and the registry as storage.
 *
 * Back-end requests run the reporter after the response (later()): the editor never waits
 * for Supertext or the Commando Center.
 */
final class Typo3Reporting
{
    private ?Reporting $reporting = null;
    /** @var array{action: string, status: int}|null the action queued for the end of this request */
    private ?array $pending = null;
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly SupertextClientFactory $clientFactory,
        private readonly Registry $registry,
        private readonly SiteList $sites,
        private readonly LoggerInterface $logger,
    ) {}

    public function reporting(): Reporting
    {
        return $this->reporting ??= new Reporting(new RegistryStateStorage($this->registry), $this->clientFactory->transport());
    }

    public function reportsEnabled(): bool
    {
        return $this->settings->areVersionReportsEnabled();
    }

    public function ping(?string $challenge): PingResponse
    {
        return Reporting::ping($challenge, $this->reportsEnabled());
    }

    /** Runs an action now (the settings overview shows its result). Never throws. */
    public function run(string $action = Reporting::ACTION_REQUEST, ?ServerRequestInterface $request = null, int $httpStatus = 401): ?ReporterResult
    {
        try {
            $installation = $this->installation($request ?? $this->currentRequest());
            $result = $this->reporting()->run($action, $installation, $this->settings->getApiKey(), $httpStatus);
            if ($result?->event !== null) {
                $this->logger->debug('Supertext version report ' . $result->event . ': ' . ($result->sent ? 'sent' : 'not sent'));
            }
            return $result;
        } catch (\Throwable $e) {
            $this->logger->debug('Supertext version report skipped: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Runs an action at the end of the request, after the response was sent where PHP allows
     * it (PHP-FPM). Several calls in one request run once; an authentication failure wins.
     */
    public function later(string $action = Reporting::ACTION_REQUEST, int $httpStatus = 401): void
    {
        if ($this->pending === null || $action === Reporting::ACTION_AUTHENTICATION_FAILED) {
            $this->pending = ['action' => $action, 'status' => $httpStatus];
        }
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        $request = $this->currentRequest();
        register_shutdown_function(function () use ($request): void {
            $pending = $this->pending;
            $this->pending = null;
            if ($pending === null) {
                return;
            }
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $this->run($pending['action'], $request, $pending['status']);
        });
    }

    /** Whether the extension configuration was saved since the last check. */
    public function settingsChanged(): bool
    {
        try {
            return $this->reporting()->settingsChanged($this->installation($this->currentRequest()));
        } catch (\Throwable) {
            return false;
        }
    }

    public function lastCheck(): ?KeyCheck
    {
        return $this->reporting()->lastCheck();
    }

    public function installation(?ServerRequestInterface $request): Installation
    {
        $siteUrl = $this->siteUrl($request);
        return new Installation(
            reportsEnabled: $this->reportsEnabled(),
            siteUrl: $siteUrl,
            callbackUrl: $siteUrl !== null ? $this->callbackUrl($siteUrl, $request) : null,
            pluginVersion: ExtensionVersion::get(),
            systemVersion: (new Typo3Version())->getVersion(),
            apiBaseUrl: $this->settings->getBaseUrl(),
            settingsFingerprint: $this->settings->getConfigurationFingerprint(),
        );
    }

    /** The first site whose base is an absolute URL, else the installation URL of the request. */
    public function siteUrl(?ServerRequestInterface $request): ?string
    {
        try {
            foreach ($this->sites->all() as $site) {
                $base = $site->getBase();
                if (in_array(strtolower($base->getScheme()), ['http', 'https'], true) && $base->getHost() !== '') {
                    return rtrim((string)$base, '/');
                }
            }
        } catch (\Throwable) {
            // no sites yet
        }
        $params = $request?->getAttribute('normalizedParams');
        if ($params instanceof NormalizedParams && $params->getRequestHost() !== '') {
            return rtrim($params->getSiteUrl(), '/');
        }
        return null;
    }

    /** The ping endpoint's absolute URL for this request (see callbackFor()). */
    public function callbackUrl(string $siteUrl, ?ServerRequestInterface $request): string
    {
        $params = $request?->getAttribute('normalizedParams');
        return self::callbackFor($siteUrl, $params instanceof NormalizedParams ? $params->getSiteUrl() : null);
    }

    /**
     * <installation URL>supertext/ping. The installation URL (TYPO3's root, e.g.
     * https://www.example.ch/ or https://www.example.ch/cms/) comes from the request when
     * it is on the site's host, else it is the site URL's origin.
     */
    public static function callbackFor(string $siteUrl, ?string $requestSiteUrl): string
    {
        $site = parse_url($siteUrl);
        $siteHost = is_array($site) ? strtolower((string)($site['host'] ?? '')) : '';
        if ($requestSiteUrl !== null && $siteHost !== '' && strtolower((string)parse_url($requestSiteUrl, PHP_URL_HOST)) === $siteHost) {
            return rtrim($requestSiteUrl, '/') . '/' . Reporting::PING_PATH;
        }
        if (!is_array($site) || !isset($site['scheme'], $site['host'])) {
            return rtrim($siteUrl, '/') . '/' . Reporting::PING_PATH;
        }
        return $site['scheme'] . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '') . '/' . Reporting::PING_PATH;
    }

    private function currentRequest(): ?ServerRequestInterface
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        return $request instanceof ServerRequestInterface ? $request : null;
    }
}
