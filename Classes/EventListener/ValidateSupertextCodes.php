<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\EventListener;

use Supertext\Typo3Translation\Configuration\SiteLanguageItems;
use Supertext\Typo3Translation\Sdk\V1\Languages;
use Supertext\Typo3Translation\Sdk\V1\Politeness;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationBeforeWriteEvent;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;

/**
 * Checks the Supertext fields of the site languages before a site configuration is written
 * (the site configuration form, or anything else using SiteWriter): supertext_code must be a code
 * of the Supertext language list (spelled as in the list), or the value already stored (a custom
 * code set in the YAML); anything else is dropped, so the language uses its default.
 * supertext_politeness must be more, less or empty, and is cleared for a language whose Supertext
 * language offers no formal/informal tone (the form hides the field then).
 */
#[AsEventListener('supertext-translation/validate-supertext-codes')]
final class ValidateSupertextCodes
{
    public function __construct(private readonly SiteConfiguration $siteConfiguration) {}

    public function __invoke(SiteConfigurationBeforeWriteEvent $event): void
    {
        $configuration = $event->getConfiguration();
        if (!is_array($configuration['languages'] ?? null)) {
            return;
        }
        try {
            $stored = $this->siteConfiguration->load($event->getSiteIdentifier());
        } catch (\Throwable) {
            $stored = [];
        }
        $validated = self::validate($configuration, self::storedCodes($stored));
        if ($validated !== $configuration) {
            $event->setConfiguration($validated);
        }
    }

    /**
     * @param array<string, mixed> $configuration
     * @param list<string> $storedCodes supertext_code values already in the stored configuration
     * @return array<string, mixed>
     */
    public static function validate(array $configuration, array $storedCodes): array
    {
        foreach (($configuration['languages'] ?? []) as $i => $language) {
            if (!is_array($language)) {
                continue;
            }
            if (array_key_exists('supertext_code', $language)) {
                $code = is_scalar($language['supertext_code']) ? trim((string)$language['supertext_code']) : '';
                if ($code !== '' && !in_array($code, $storedCodes, true)) {
                    $code = Languages::matchTarget($code) ?? '';
                }
                $configuration['languages'][$i]['supertext_code'] = $code;
            }
            if (array_key_exists('supertext_politeness', $language)) {
                $politeness = $language['supertext_politeness'];
                $locale = is_scalar($language['locale'] ?? null) ? (string)$language['locale'] : '';
                $code = (string)($configuration['languages'][$i]['supertext_code'] ?? '');
                // The form hides the tone for languages without one, so a tone left from an earlier choice goes too.
                if (!in_array($politeness, [Politeness::MORE, Politeness::LESS, ''], true)
                    || $politeness !== '' && !SiteLanguageItems::politenessOffered($locale, $code)) {
                    $configuration['languages'][$i]['supertext_politeness'] = '';
                }
            }
        }
        return $configuration;
    }

    /**
     * @param array<string, mixed> $configuration
     * @return list<string>
     */
    private static function storedCodes(array $configuration): array
    {
        $codes = [];
        foreach (($configuration['languages'] ?? []) as $language) {
            if (is_array($language) && is_string($language['supertext_code'] ?? null) && trim($language['supertext_code']) !== '') {
                $codes[] = trim($language['supertext_code']);
            }
        }
        return $codes;
    }
}
