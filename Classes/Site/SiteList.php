<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Site;

use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * All sites of the installation.
 *
 * Normally TYPO3's SiteFinder. The extension configuration form, though, is rendered by the
 * install tool in its failsafe mode (`?__typo3_install`): there the container has neither
 * SiteFinder nor this extension's services, so the sites are read from their `config.yaml`
 * files directly, the same way TYPO3's SiteConfiguration does (without its cache and
 * `settings.yaml`, which the status block doesn't need).
 */
final class SiteList
{
    public function __construct(private readonly ?SiteFinder $siteFinder = null) {}

    /** @return array<string, Site> keyed by site identifier */
    public function all(): array
    {
        if ($this->siteFinder !== null) {
            return $this->siteFinder->getAllSites();
        }
        return self::fromFiles(Environment::getConfigPath() . '/sites', GeneralUtility::makeInstance(YamlFileLoader::class));
    }

    /** @return array<string, Site> */
    public static function fromFiles(string $sitesDirectory, YamlFileLoader $loader): array
    {
        $files = glob(rtrim($sitesDirectory, '/') . '/*/config.yaml') ?: [];
        sort($files);
        $sites = [];
        foreach ($files as $file) {
            $identifier = basename(dirname($file));
            $configuration = $loader->load($file);
            $rootPageId = (int)($configuration['rootPageId'] ?? 0);
            if ($rootPageId > 0) {
                $sites[$identifier] = new Site($identifier, $rootPageId, $configuration);
            }
        }
        return $sites;
    }
}
