<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Files;

/** A site language and the site's default language, as LanguageResolver reads them. */
final class FileLanguage
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $sourceLocale,
        public readonly string $sourceOverride,
        public readonly string $targetLocale,
        public readonly string $targetOverride,
    ) {}
}
