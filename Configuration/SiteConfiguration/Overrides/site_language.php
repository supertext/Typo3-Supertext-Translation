<?php

// Site Management > Sites > (site) > Languages > (language) > "Supertext":
// the Supertext language (supertext_code, a select of Supertext's target languages; empty = the
// SDK's default for the locale) and tone (supertext_politeness, only where the language offers it)
// of a site language, and "Translation review" (supertext_review, target languages only:
// translations into it go through Supertext's review service, setting reviewUrl).
// Stored in config/sites/<site>/config.yaml like the other language settings.

defined('TYPO3') or die();

(static function (): void {
    $ll = 'LLL:EXT:supertext_translation/Resources/Private/Language/locallang.xlf:';
    $items = \Supertext\Typo3Translation\Sdk\V1\Languages::targetOptions();
    $items = array_merge(
        [['label' => $ll . 'siteLanguage.code.choose', 'value' => '']],
        array_map(static fn(array $language): array => ['label' => $language['name'] . ' (' . $language['code'] . ')', 'value' => $language['code']], $items),
    );
    $proc = \Supertext\Typo3Translation\Configuration\SiteLanguageItems::class;

    $GLOBALS['SiteConfiguration']['site_language']['columns']['supertext_code'] = [
        'label' => $ll . 'siteLanguage.code',
        'description' => $ll . 'siteLanguage.code.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            // Replaced by SiteLanguageItems->targetItems(): "Default (de-DE)" or "— choose —" for
            // the language's locale, the names in the user's language, a custom YAML code kept.
            'items' => $items,
            'itemsProcFunc' => $proc . '->targetItems',
            'default' => '',
        ],
        // Re-render, so the default option and the tone field follow the chosen language.
        'onChange' => 'reload',
    ];
    $GLOBALS['SiteConfiguration']['site_language']['columns']['supertext_politeness'] = [
        'label' => $ll . 'siteLanguage.politeness',
        'description' => $ll . 'siteLanguage.politeness.description',
        'displayCond' => [
            'AND' => [
                'FIELD:languageId:>:0',
                'USER:' . $proc . '->offersPoliteness',
            ],
        ],
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => $ll . 'status.tone.default', 'value' => ''],
                ['label' => $ll . 'status.tone.more', 'value' => 'more'],
                ['label' => $ll . 'status.tone.less', 'value' => 'less'],
            ],
            'default' => '',
        ],
    ];
    $GLOBALS['SiteConfiguration']['site_language']['columns']['supertext_review'] = [
        'label' => $ll . 'siteLanguage.review',
        'description' => $ll . 'siteLanguage.review.description',
        'displayCond' => 'FIELD:languageId:>:0',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ];
    $GLOBALS['SiteConfiguration']['site_language']['palettes']['supertext'] = [
        'label' => $ll . 'siteLanguage.palette',
        'showitem' => 'supertext_code, supertext_politeness, --linebreak--, supertext_review',
    ];
    $GLOBALS['SiteConfiguration']['site_language']['types']['1']['showitem'] .= ', --palette--;;supertext';
})();
