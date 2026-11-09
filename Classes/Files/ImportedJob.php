<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Files;

/** The result of one job (one page in one language) of an imported file. */
final class ImportedJob
{
    public ?int $page = null;
    public string $title = '';
    public string $languageTitle = '';
    /** The job's error, if it couldn't be imported at all. */
    public ?TranslationFileGlueError $error = null;
    public int $imported = 0;
    public int $sourceChanged = 0;
    public int $untranslated = 0;
    public int $invalid = 0;
    public int $missing = 0;
    /** @var list<string> write-back errors, already in the user's language */
    public array $messages = [];

    public function __construct(
        public readonly string $reference,
        public readonly string $targetLanguage,
    ) {}
}
