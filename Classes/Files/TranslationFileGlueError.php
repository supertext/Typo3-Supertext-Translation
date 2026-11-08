<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Files;

/** An import or export problem of the extension's own (the SDK's file errors are TranslationFileError). */
final class TranslationFileGlueError extends \RuntimeException
{
    /** Nothing to translate on the page. */
    public const NOTHING_TO_TRANSLATE = 'nothing_to_translate';
    /** The upload is larger than TranslationFileGlue::MAX_UPLOAD_BYTES. */
    public const TOO_LARGE = 'too_large';
    /** The file was exported by another Supertext plugin ($argument = its name). */
    public const OTHER_SYSTEM = 'other_system';
    /** The page of the reference doesn't exist (or the reference is unknown). */
    public const NOT_FOUND = 'not_found';
    /** The page's site has no such language ($argument = the language). */
    public const NO_LANGUAGE = 'no_language';

    public function __construct(
        public readonly string $errorCode,
        public readonly string $argument = '',
    ) {
        parent::__construct($argument !== '' ? $errorCode . ': ' . $argument : $errorCode);
    }
}
