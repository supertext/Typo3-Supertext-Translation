<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Upgrades;

use Supertext\Typo3Translation\Service\LanguageResolver;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Admin Tools > Upgrade > Upgrade Wizard: "Supertext: language codes of the site languages".
 * Rewrites the supertext_code values of older versions (free text in 0.1) to the codes of the
 * Supertext language select, so the select shows them (see LanguageResolver::migratedCode()).
 * Codes the list doesn't know are left as they are. Repeatable and harmless: it changes nothing
 * once every value is a code from the list.
 *
 * The Install namespace works on TYPO3 13 and, as an alias of the Core one, on 14.
 */
#[UpgradeWizard('supertextTranslation_languageCodes')]
final class SupertextCodeUpgradeWizard implements UpgradeWizardInterface
{
    public function __construct(
        private readonly SiteConfiguration $siteConfiguration,
        private readonly SiteWriter $siteWriter,
    ) {}

    public function getTitle(): string
    {
        return 'Supertext: language codes of the site languages';
    }

    public function getDescription(): string
    {
        return 'Rewrites the Supertext language (supertext_code) of every site language to a code of the Supertext language list'
            . ' ("de_ch" => "de-CH", "de" => "de-DE", "de_DE_formal" => "de-DE" with formal tone). Unknown codes stay as they are.';
    }

    public function executeUpdate(): bool
    {
        foreach ($this->changes() as $identifier => $configuration) {
            $this->siteWriter->write($identifier, $configuration);
        }
        return true;
    }

    public function updateNecessary(): bool
    {
        return $this->changes() !== [];
    }

    public function getPrerequisites(): array
    {
        return [];
    }

    /** @return array<string, array<string, mixed>> the changed configurations by site identifier */
    private function changes(): array
    {
        $changes = [];
        foreach ($this->siteConfiguration->getAllSiteConfigurationPaths() as $identifier => $path) {
            try {
                $configuration = $this->siteConfiguration->load((string)$identifier);
            } catch (\Throwable) {
                continue;
            }
            $migrated = self::migrate($configuration);
            if ($migrated !== null) {
                $changes[(string)$identifier] = $migrated;
            }
        }
        return $changes;
    }

    /**
     * @param array<string, mixed> $configuration a site configuration
     * @return array<string, mixed>|null the configuration with migrated codes, null when nothing changes
     */
    public static function migrate(array $configuration): ?array
    {
        $changed = false;
        foreach (($configuration['languages'] ?? []) as $i => $language) {
            if (!is_array($language) || !is_string($language['supertext_code'] ?? null)) {
                continue;
            }
            $result = LanguageResolver::migratedCode($language['supertext_code'], (string)($language['supertext_politeness'] ?? ''));
            if ($result === null) {
                continue;
            }
            $configuration['languages'][$i]['supertext_code'] = $result['code'];
            if ($result['politeness'] !== '') {
                $configuration['languages'][$i]['supertext_politeness'] = $result['politeness'];
            }
            $changed = true;
        }
        return $changed ? $configuration : null;
    }
}
