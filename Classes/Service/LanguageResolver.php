<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Service;

use Supertext\Typo3Translation\Sdk\V1\Languages;
use Supertext\Typo3Translation\Sdk\V1\Politeness;
use Supertext\Typo3Translation\Sdk\V1\SupertextException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Maps TYPO3 site languages to Supertext language codes, with the Supertext SDK's
 * rules (the same in every Supertext plugin, Languages::target()):
 *
 * - The site language's `supertext_code` wins (the administrator's choice in the select,
 *   sent as it is).
 * - Otherwise its locale without encoding (de_CH.UTF-8 => de_CH) if it equals a Supertext
 *   code apart from case and _/- (de_CH => de-CH, es => es).
 * - Otherwise the SDK's default where one is obvious (de => de-DE, en => en-US, nl_NL => nl,
 *   es_ES => es, zh_CN => zh-Hans, zh_TW => zh-Hant, sr => sr-Latn).
 * - Anything else (en_IE, de_LI, gsw, sr_RS, ...) is SupertextException unsupported_language:
 *   nothing is sent, and the editor is asked to have the language mapped.
 *
 * The source is the default language's code reduced to what Supertext accepts as a source
 * (en_US => en). Set the codes per language in Sites > Setup > edit the site > Languages >
 * (language) > Supertext, or in config/sites/<site>/config.yaml:
 *
 *   languages:
 *     - languageId: 1
 *       locale: de_CH.UTF-8
 *       supertext_code: de-CH        # optional, Supertext target code
 *       supertext_politeness: more   # optional: more (formal) | less (informal)
 *       supertext_review: true       # optional: translations into it go through the review service
 */
final class LanguageResolver
{
    public function __construct(private readonly SiteFinder $siteFinder) {}

    /**
     * @return array{target: string, source: string, politeness: string, review: bool, title: string}|null null: the page's site has no such language
     * @throws SupertextException unsupported_language when a language has no Supertext code
     */
    public function resolve(int $pageId, int $targetLanguageId): ?array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $target = $site->getLanguageById($targetLanguageId);
        } catch (SiteNotFoundException|\InvalidArgumentException) {
            return null;
        }
        return $this->resolveLanguage($site, $target);
    }

    /** The site language's title, '' when there is none. */
    public function titleOf(int $pageId, int $languageId): string
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId)->getLanguageById($languageId)->getTitle();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array{target: string, source: string, politeness: string, review: bool, title: string}
     * @throws SupertextException unsupported_language
     */
    public function resolveLanguage(Site $site, SiteLanguage $target): array
    {
        return [
            'target' => self::targetCode(self::localeOf($target), self::overrideOf($target)),
            'source' => self::sourceCode(self::localeOf($site->getDefaultLanguage()), self::overrideOf($site->getDefaultLanguage())),
            'politeness' => self::politenessOf($target),
            'review' => self::reviewOf($target),
            'title' => $target->getTitle(),
        ];
    }

    /**
     * The Supertext target for a locale: the override if set, else an exact Supertext code,
     * else the SDK's default.
     *
     * @throws SupertextException unsupported_language (targetLang = the locale)
     */
    public static function targetCode(string $locale, string $override = ''): string
    {
        return Languages::target($locale, $override !== '' ? [$locale => $override] : []);
    }

    /**
     * The Supertext source for the default language: the SDK's Languages::source() with the same
     * mapping, so one supertext_code serves both directions (de-CH => de); else the locale's
     * language (en_US => en).
     *
     * @throws SupertextException unsupported_language (sourceLang = the locale)
     */
    public static function sourceCode(string $locale, string $override = ''): string
    {
        return Languages::source($locale, $override !== '' ? [$locale => $override] : []);
    }

    /** True when a language without supertext_code is sent with the SDK's default, not an exact match of its locale. */
    public static function usesDefault(string $locale, string $override = ''): bool
    {
        return $override === '' && Languages::matchTarget($locale) === null && Languages::defaultTarget($locale) !== null;
    }

    /**
     * The supertext_code an upgrade stores for a value of an older version, with the tone, or
     * null when nothing changes. Values of 0.1 were free text: case and _/- are normalized
     * ("de_ch" => "de-CH"), a bare or regional language gets the code 0.1 sent ("de" => "de-DE",
     * "es_ES" => "es"), and a TYPO3 variant becomes the tone ("de_DE_formal" => de-DE + more)
     * unless one is set. A value the list doesn't know stays (it is sent as it is).
     *
     * @return array{code: string, politeness: string}|null
     */
    public static function migratedCode(string $code, string $politeness = ''): ?array
    {
        $code = trim($code);
        if ($code === '' || Languages::matchTarget($code) === $code) {
            return null;
        }
        $tone = '';
        if (preg_match('/^(.+?)[_-](formal|informal)$/i', $code, $m) === 1) {
            $code = $m[1];
            $tone = strtolower($m[2]) === 'formal' ? Politeness::MORE : Politeness::LESS;
        }
        $migrated = Languages::suggestTarget(self::stripEncoding($code));
        if ($migrated === null) {
            return null;
        }
        $politeness = in_array($politeness, [Politeness::MORE, Politeness::LESS], true) ? $politeness : '';
        if ($politeness === '' && $tone !== '' && Languages::supportsPoliteness($migrated) === true) {
            $politeness = $tone;
        }
        return ['code' => $migrated, 'politeness' => $politeness];
    }

    /** The target code, or null when the language has neither a mapping nor a default (for the status overview). */
    public static function targetCodeOrNull(string $locale, string $override = ''): ?string
    {
        try {
            return self::targetCode($locale, $override);
        } catch (SupertextException) {
            return null;
        }
    }

    /** The source code, or null when the default language has no Supertext source (for the status overview). */
    public static function sourceCodeOrNull(string $locale, string $override = ''): ?string
    {
        try {
            return self::sourceCode($locale, $override);
        } catch (SupertextException) {
            return null;
        }
    }

    /** The language's supertext_code (trimmed), '' when not set. */
    public static function overrideOf(SiteLanguage $language): string
    {
        return trim((string)($language->toArray()['supertext_code'] ?? ''));
    }

    /** more, less or default, from supertext_politeness. */
    public static function politenessOf(SiteLanguage $language): string
    {
        $politeness = (string)($language->toArray()['supertext_politeness'] ?? '');
        return in_array($politeness, [Politeness::MORE, Politeness::LESS], true) ? $politeness : Politeness::DEFAULT;
    }

    /** "Translation review" (supertext_review) of a target language: translations into it go through the review service. */
    public static function reviewOf(SiteLanguage $language): bool
    {
        return $language->getLanguageId() > 0 && (bool)($language->toArray()['supertext_review'] ?? false);
    }

    /** The locale without encoding or modifier ("de_CH.UTF-8" => "de_CH"). */
    public static function localeOf(SiteLanguage $language): string
    {
        $name = $language->getLocale()->getName();
        $locale = $name !== '' ? $name : $language->getLocale()->getLanguageCode();
        return self::stripEncoding($locale);
    }

    public static function stripEncoding(string $locale): string
    {
        return trim((string)preg_replace('/[.@].*$/', '', $locale));
    }
}
