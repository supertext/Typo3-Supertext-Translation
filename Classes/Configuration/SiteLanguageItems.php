<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Configuration;

use Supertext\Typo3Translation\Localization\Labels;
use Supertext\Typo3Translation\Sdk\V1\Languages;
use Supertext\Typo3Translation\Service\LanguageResolver;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;

/**
 * The "Supertext language" select of a site language (Sites > Setup > edit the site >
 * Languages > (language) > Supertext), see Configuration/SiteConfiguration/Overrides/site_language.php.
 *
 * - itemsProcFunc targetItems(): the empty option is "Default (de-DE)" with the SDK's
 *   suggestTarget() for the language's locale (exact match, else default), or "— choose —"
 *   when the locale has none; then every Supertext target (Languages::targetOptions()) as
 *   "Name (code)", the name in the backend user's language where PHP's intl knows it; a stored
 *   code that isn't in the list (set in the YAML) stays selectable.
 * - displayCond offersPoliteness(): the tone field only for targets that offer formal/informal.
 */
final class SiteLanguageItems
{
    /** @param array<string, mixed> $params */
    #[AsAllowedCallable]
    public function targetItems(array &$params): void
    {
        $row = is_array($params['row'] ?? null) ? $params['row'] : [];
        $labels = Labels::forUser($GLOBALS['BE_USER'] ?? null);
        $params['items'] = self::items(
            self::stringOf($row['locale'] ?? ''),
            self::stringOf($row['supertext_code'] ?? ''),
            self::uiLanguage(),
            static fn(string $key, string ...$arguments): string => $labels->get($key, ...$arguments),
        );
    }

    /**
     * The select's items for a site language.
     *
     * @param callable(string, string...): string $label
     * @return list<array{label: string, value: string}>
     */
    public static function items(string $locale, string $stored, string $uiLanguage, callable $label): array
    {
        $suggested = Languages::suggestTarget(LanguageResolver::stripEncoding($locale));
        $items = [[
            'label' => $suggested !== null ? $label('siteLanguage.code.default', $suggested) : $label('siteLanguage.code.choose'),
            'value' => '',
        ]];
        $targets = [];
        foreach (Languages::targetOptions() as $target) {
            $targets[] = ['label' => self::displayName($target['code'], $target['name'], $uiLanguage) . ' (' . $target['code'] . ')', 'value' => $target['code']];
        }
        if ($uiLanguage !== 'en' && class_exists(\Collator::class)) {
            $collator = new \Collator($uiLanguage);
            usort($targets, static fn(array $a, array $b): int => (int)$collator->compare($a['label'], $b['label']));
        }
        array_push($items, ...$targets);
        $stored = trim($stored);
        if ($stored !== '' && !Languages::isSupportedTarget($stored)) {
            $items[] = ['label' => $label('siteLanguage.code.custom', $stored), 'value' => $stored];
        }
        return $items;
    }

    /**
     * Display condition of supertext_politeness: shown for every target language whose
     * Supertext language (the chosen one, else the default) offers formal/informal, or isn't known yet.
     *
     * @param array<string, mixed> $params
     */
    #[AsAllowedCallable]
    public function offersPoliteness(array $params): bool
    {
        $row = is_array($params['record'] ?? null) ? $params['record'] : [];
        return self::politenessOffered(self::stringOf($row['locale'] ?? ''), self::stringOf($row['supertext_code'] ?? ''));
    }

    public static function politenessOffered(string $locale, string $code): bool
    {
        $target = LanguageResolver::targetCodeOrNull(LanguageResolver::stripEncoding($locale), trim($code));
        return $target === null || Languages::supportsPoliteness($target) !== false;
    }

    /** Cantons of the Swiss German targets (gsw-u-sd-ch…), for their names in other languages. */
    private const SWISS_GERMAN_CANTONS = [
        'chzh' => ['de' => 'Zürich', 'fr' => 'Zurich', 'it' => 'Zurigo'],
        'chbe' => ['de' => 'Bern', 'fr' => 'Berne', 'it' => 'Berna'],
    ];

    /** The language's name in the user's interface language (intl), else the SDK's English name. */
    public static function displayName(string $code, string $englishName, string $uiLanguage): string
    {
        if ($uiLanguage === 'en' || !class_exists(\Locale::class)) {
            return $englishName;
        }
        if (preg_match('/^gsw-u-sd-(\w+)$/', $code, $m) === 1) {
            // ICU would say "(Unicode subdivision: chzh)".
            $canton = self::SWISS_GERMAN_CANTONS[$m[1]][$uiLanguage] ?? null;
            $name = \Locale::getDisplayLanguage('gsw', $uiLanguage);
            return $canton !== null && $name !== 'gsw' ? self::ucfirst($name) . ' (' . $canton . ')' : $englishName;
        }
        $name = \Locale::getDisplayName($code, $uiLanguage);
        return $name !== $code ? self::ucfirst($name) : $englishName;
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** The backend user's interface language as a bare language code (default: en). */
    private static function uiLanguage(): string
    {
        $lang = '';
        $user = $GLOBALS['BE_USER'] ?? null;
        if (is_object($user) && isset($user->user) && is_array($user->user)) {
            $lang = (string)($user->user['lang'] ?? '');
        }
        // TYPO3 stores "default" for English, else a language key such as "de" or "pt_BR".
        if ($lang === '' || $lang === 'default') {
            return 'en';
        }
        $lang = strtolower(substr($lang, 0, 2));
        return preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : 'en';
    }

    private static function stringOf(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_scalar($value) ? (string)$value : '';
    }
}
