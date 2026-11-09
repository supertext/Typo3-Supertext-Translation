<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Configuration;

use Supertext\Typo3Translation\Api\SupertextClientFactory;
use Supertext\Typo3Translation\PluginHealth\InstallationReporter;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Typed access to the extension configuration (Admin Tools > Settings > Extension Configuration).
 */
final class ExtensionSettings
{
    public const EXTENSION_KEY = 'supertext_translation';

    private const ENVIRONMENTS = [
        'live' => 'https://api.supertext.com/v1/',
        'staging' => 'https://api.staging.supertext.com/v1/',
        'testing' => 'https://api.testing.supertext.com/v1/',
    ];

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        try {
            $config = $extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable) {
            $config = [];
        }
        $this->config = is_array($config) ? $config : [];
    }

    public function isEnabled(): bool
    {
        return (bool)($this->config['enabled'] ?? true);
    }

    public function getApiKey(): string
    {
        $fromEnv = getenv('SUPERTEXT_API_KEY');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }
        return trim((string)($this->config['apiKey'] ?? ''));
    }

    public function getBaseUrl(): string
    {
        $endpoint = trim((string)(getenv('SUPERTEXT_API_ENDPOINT') ?: ($this->config['endpoint'] ?? '')));
        if ($endpoint === '') {
            $environment = (string)($this->config['environment'] ?? 'live');
            $endpoint = self::ENVIRONMENTS[$environment] ?? self::ENVIRONMENTS['live'];
        }
        return rtrim($endpoint, '/') . '/';
    }

    /**
     * The review service for site languages with "Translation review" on: SUPERTEXT_REVIEW_URL
     * (when not empty) wins over the setting reviewUrl, whose default is Supertext's review
     * service. '' = review off for every language (also when it is the normal API URL).
     */
    public function getReviewUrl(): string
    {
        $fromEnv = getenv('SUPERTEXT_REVIEW_URL');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            $url = trim($fromEnv);
        } elseif (array_key_exists('reviewUrl', $this->config)) {
            $url = trim((string)$this->config['reviewUrl']);
        } else {
            $url = SupertextClientFactory::REVIEW_URL;
        }
        if ($url === '') {
            return '';
        }
        $url = rtrim($url, '/') . '/';
        return $url === $this->getBaseUrl() ? '' : $url;
    }

    public function getPollTimeout(): int
    {
        return max(10, (int)($this->config['pollTimeout'] ?? 180));
    }

    public function getPollInterval(): int
    {
        return max(1, (int)($this->config['pollInterval'] ?? 2));
    }

    /** @return string[] */
    public function getSkipBodytextCTypes(): array
    {
        $value = (string)($this->config['skipBodytextCTypes'] ?? 'html');
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function shouldRegenerateSlugs(): bool
    {
        return (bool)($this->config['regenerateSlugs'] ?? true);
    }

    /** The "Send version information to Supertext" setting; null when it was never saved (= on). */
    public function getVersionReportsSetting(): ?bool
    {
        if (!array_key_exists('versionReports', $this->config) || $this->config['versionReports'] === '') {
            return null;
        }
        return (bool)$this->config['versionReports'];
    }

    /** Whether version reports are on: SUPERTEXT_VERSION_REPORTS (0/1, off/on, ...) wins over the setting. */
    public function areVersionReportsEnabled(): bool
    {
        return InstallationReporter::reportsEnabled($this->getVersionReportsSetting());
    }

    /** True when SUPERTEXT_VERSION_REPORTS decides, whatever the setting says. */
    public function isVersionReportsSetByEnvironment(): bool
    {
        return InstallationReporter::reportsEnabled(true) === InstallationReporter::reportsEnabled(false);
    }

    /**
     * A hash of the saved extension configuration, to notice that an administrator saved
     * it (TYPO3 has no event for that). Kept locally, never sent anywhere.
     */
    public function getConfigurationFingerprint(): string
    {
        $config = $this->config;
        unset($config['status']);
        ksort($config);
        return hash('sha256', (string)json_encode($config));
    }
}
