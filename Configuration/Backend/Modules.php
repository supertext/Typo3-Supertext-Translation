<?php

declare(strict_types=1);

use Supertext\Typo3Translation\Controller\TranslationFilesController;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Backend module "Supertext" (export for translation, import translation) next to the Page
 * module. TYPO3 14 renamed the main module "web" to "content" and doesn't resolve the old
 * name for a parent, so the parent depends on the version.
 */
return [
    'supertext_translation_files' => [
        'parent' => (new Typo3Version())->getMajorVersion() >= 14 ? 'content' : 'web',
        'position' => ['bottom'],
        'access' => 'user',
        'path' => '/module/web/supertext',
        'iconIdentifier' => 'supertext-translation-module',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'labels' => [
            'title' => 'LLL:EXT:supertext_translation/Resources/Private/Language/locallang.xlf:module.title',
            'description' => 'LLL:EXT:supertext_translation/Resources/Private/Language/locallang.xlf:module.description',
            'shortDescription' => 'LLL:EXT:supertext_translation/Resources/Private/Language/locallang.xlf:module.shortDescription',
        ],
        'routes' => [
            '_default' => [
                'target' => TranslationFilesController::class . '::handleRequest',
            ],
        ],
    ],
];
