<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Files;

final class ExportedFile
{
    public function __construct(
        public readonly string $fileName,
        public readonly string $contentType,
        public readonly string $content,
        public readonly int $segments,
    ) {}
}
