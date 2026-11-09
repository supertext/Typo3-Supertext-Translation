<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Api;

use Supertext\Typo3Translation\Configuration\ExtensionSettings;
use Supertext\Typo3Translation\Configuration\ExtensionVersion;
use Supertext\Typo3Translation\Sdk\Http\HttpTransport;
use Supertext\Typo3Translation\Sdk\V1\Client;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Creates the Supertext SDK client (bundled in lib/supertext-sdk, namespace
 * Supertext\Typo3Translation\Sdk; see scripts/vendor-sdk.sh) from the
 * extension configuration. The SDK handles the protocol: authentication header,
 * multipart upload, 429 retries, polling, download, delete and HTML packing.
 *
 * Translations into a site language with "Translation review" on (supertext_review) use a second
 * client whose base URL is the review service (setting reviewUrl); everything else (the API key
 * check, version reports, translation files) never does.
 */
final class SupertextClientFactory
{
    /**
     * Supertext's review service (translation proxy of Supertext Localization Review). It speaks
     * the same API v1 and forwards to the live Supertext API with the extension's key, keeping
     * every translation so reviewers can correct them on the website. Default of reviewUrl.
     */
    public const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

    /** Sent in the User-Agent after the SDK's own name, with the version from ext_emconf.php. */
    public static function userAgent(): string
    {
        return 'TYPO3-Supertext-Translation/' . ExtensionVersion::get();
    }

    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly RequestFactory $requestFactory,
    ) {}

    public function hasApiKey(): bool
    {
        return $this->settings->getApiKey() !== '';
    }

    /**
     * The client for translations. $review: the target language has "Translation review" on, so
     * the client talks to the review service (same key, timeouts, transport and User-Agent);
     * without a review URL (reviewUrl = '') it is the direct client. A job is collected with the
     * client that submitted it (translateTexts does the whole round trip with one client).
     */
    public function create(bool $review = false): Client
    {
        $timeout = $this->settings->getPollTimeout();
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 60);
        }
        return $this->createWith($review, $this->transport());
    }

    /** create() with another transport (tests). */
    public function createWith(bool $review, HttpTransport $transport): Client
    {
        return new Client(
            $this->settings->getApiKey(),
            baseUrl: $this->baseUrl($review),
            pollInterval: (float)$this->settings->getPollInterval(),
            timeout: (float)$this->settings->getPollTimeout(),
            userAgent: self::userAgent(),
            transport: $transport,
        );
    }

    /** The API base URL for a translation: the review service when $review and a review URL is set. */
    public function baseUrl(bool $review): string
    {
        $reviewUrl = $review ? $this->settings->getReviewUrl() : '';
        return $reviewUrl !== '' ? $reviewUrl : $this->settings->getBaseUrl();
    }

    /** TYPO3's HTTP client for the SDK (translations, the API key check and version reports). */
    public function transport(): Typo3HttpTransport
    {
        return new Typo3HttpTransport($this->requestFactory);
    }

    public function getApiKey(): string
    {
        return $this->settings->getApiKey();
    }
}
