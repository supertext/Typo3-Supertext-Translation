<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Files;

/**
 * What TranslationFileGlue needs from TYPO3: the page's segments, its site languages and the
 * write-back. Typo3TranslationFileStore is the real one; the tests use a fake.
 */
interface TranslationFileStore
{
    /** The page (default language) with its segments, null when it doesn't exist. */
    public function item(int $page): ?FileItem;

    /** The page's site language with this id (not the default language), null when there is none. */
    public function language(int $page, int $language): ?FileLanguage;

    /**
     * Writes imported translations into the page's translation (and its content elements'),
     * localizing what doesn't exist yet, with the same rules as API translations.
     *
     * @param array<string, string> $texts segment key => translated text
     * @return list<string> errors, already in the user's language
     */
    public function write(int $page, int $language, array $texts): array;
}
