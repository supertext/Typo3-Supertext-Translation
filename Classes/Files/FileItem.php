<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Files;

use Supertext\Typo3Translation\Sdk\Files\FileSegment;

/** A page and its translatable segments (keys `<table>:<uid>:<field>` of the default-language records). */
final class FileItem
{
    /** @param list<FileSegment> $segments */
    public function __construct(
        public readonly int $page,
        public readonly string $title,
        public readonly array $segments,
    ) {}
}
