<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Files;

use Supertext\Typo3Translation\Sdk\Files\FileJob;
use Supertext\Typo3Translation\Sdk\Files\ImportedSegment;
use Supertext\Typo3Translation\Sdk\Files\TranslationFiles;
use Supertext\Typo3Translation\Sdk\V1\SupertextException;
use Supertext\Typo3Translation\Service\LanguageResolver;

/**
 * Export for translation / import translation (XLIFF 1.2 or HTML) with the Supertext SDK's
 * TranslationFiles, without TYPO3 classes: the store (Typo3TranslationFileStore) collects the
 * page's segments with the same FieldCollector as API translations and writes the imported
 * texts back through TranslationService.
 *
 * Job reference: `page:<page uid>:<site language id>`, e.g. `page:12:1`.
 */
final class TranslationFileGlue
{
    /** The job's `plugin`: this repository's name. */
    public const PLUGIN = 'Typo3-Supertext-Translation';
    public const SHORT_NAME = 'typo3';
    public const FORMAT_XLIFF = 'xliff';
    public const FORMAT_HTML = 'html';
    /** Uploads larger than this are refused before they are read (10 MB). */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly TranslationFileStore $store) {}

    public static function reference(int $page, int $language): string
    {
        return sprintf('page:%d:%d', $page, $language);
    }

    /** @return array{page: int, language: int}|null null: not a reference of this extension */
    public static function parseReference(string $reference): ?array
    {
        if (preg_match('/^page:([1-9][0-9]{0,9}):([1-9][0-9]{0,9})$/D', $reference, $m) !== 1) {
            return null;
        }
        return ['page' => (int)$m[1], 'language' => (int)$m[2]];
    }

    /**
     * `typo3-<page>-<target>.xlf` (or `.html`), `typo3-<page>.xlf` for several targets.
     *
     * @param list<string> $targets Supertext target codes
     */
    public static function fileName(int $page, array $targets, string $format): string
    {
        $name = self::SHORT_NAME . '-' . $page;
        if (count($targets) === 1) {
            $name .= '-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $targets[0]);
        }
        return $name . ($format === self::FORMAT_HTML ? '.html' : '.xlf');
    }

    public static function contentType(string $format): string
    {
        return $format === self::FORMAT_HTML ? 'text/html; charset=utf-8' : 'application/x-xliff+xml';
    }

    /**
     * The Supertext code LanguageResolver resolves, or the locale with `-` for `_` when it can't
     * (a file may go to any translator, so an unmapped language doesn't block the export).
     */
    public static function code(string $locale, string $override, bool $source): string
    {
        try {
            return $source ? LanguageResolver::sourceCode($locale, $override) : LanguageResolver::targetCode($locale, $override);
        } catch (SupertextException) {
            return str_replace('_', '-', $locale);
        }
    }

    /**
     * @param list<int> $languages site language ids (HTML: exactly one)
     * @throws TranslationFileGlueError not_found, no_language, nothing_to_translate
     * @throws \InvalidArgumentException unknown format, HTML with several languages, no language
     */
    public function export(int $page, array $languages, string $format): ExportedFile
    {
        if (!in_array($format, [self::FORMAT_XLIFF, self::FORMAT_HTML], true)) {
            throw new \InvalidArgumentException('The format is xliff or html.');
        }
        $languages = array_values(array_unique($languages));
        if ($languages === []) {
            throw new \InvalidArgumentException('Choose at least one target language.');
        }
        if ($format === self::FORMAT_HTML && count($languages) !== 1) {
            throw new \InvalidArgumentException('An HTML file holds exactly one target language.');
        }
        $item = $this->store->item($page);
        if ($item === null) {
            throw new TranslationFileGlueError(TranslationFileGlueError::NOT_FOUND);
        }
        if ($item->segments === []) {
            throw new TranslationFileGlueError(TranslationFileGlueError::NOTHING_TO_TRANSLATE);
        }
        $jobs = [];
        $targets = [];
        foreach ($languages as $id) {
            $language = $this->store->language($page, $id);
            if ($language === null) {
                throw new TranslationFileGlueError(TranslationFileGlueError::NO_LANGUAGE, (string)$id);
            }
            $target = self::code($language->targetLocale, $language->targetOverride, false);
            $targets[] = $target;
            $jobs[] = new FileJob(
                self::PLUGIN,
                self::reference($page, $id),
                self::code($language->sourceLocale, $language->sourceOverride, true),
                $target,
                $item->segments,
            );
        }
        $content = $format === self::FORMAT_HTML ? TranslationFiles::exportHtml($jobs[0]) : TranslationFiles::exportXliff($jobs);
        return new ExportedFile(self::fileName($page, $targets, $format), self::contentType($format), $content, count($item->segments));
    }

    /**
     * Imports a translated file from disk; files over MAX_UPLOAD_BYTES are refused before reading.
     *
     * @return list<ImportedJob>
     * @throws TranslationFileGlueError too_large
     * @throws \Supertext\Typo3Translation\Sdk\Files\TranslationFileError invalid_file, missing_metadata, too_large
     */
    public function importFile(string $path, bool $includeChanged): array
    {
        $size = @filesize($path);
        if ($size === false || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Cannot read %s.', $path));
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new TranslationFileGlueError(TranslationFileGlueError::TOO_LARGE);
        }
        return $this->import((string)file_get_contents($path), $includeChanged);
    }

    /**
     * @return list<ImportedJob> one per job of the file
     * @throws TranslationFileGlueError too_large
     * @throws \Supertext\Typo3Translation\Sdk\Files\TranslationFileError invalid_file, missing_metadata, too_large
     */
    public function import(string $content, bool $includeChanged): array
    {
        if (strlen($content) > self::MAX_UPLOAD_BYTES) {
            throw new TranslationFileGlueError(TranslationFileGlueError::TOO_LARGE);
        }
        $results = [];
        foreach (TranslationFiles::read($content) as $file) {
            $results[] = $job = new ImportedJob($file->reference, $file->targetLanguage);
            if ($file->plugin !== self::PLUGIN) {
                $job->error = new TranslationFileGlueError(TranslationFileGlueError::OTHER_SYSTEM, $file->plugin);
                continue;
            }
            $reference = self::parseReference($file->reference);
            $item = $reference !== null ? $this->store->item($reference['page']) : null;
            if ($reference === null || $item === null) {
                $job->error = new TranslationFileGlueError(TranslationFileGlueError::NOT_FOUND);
                continue;
            }
            $job->page = $item->page;
            $job->title = $item->title;
            $language = $this->store->language($item->page, $reference['language']);
            if ($language === null) {
                $job->error = new TranslationFileGlueError(TranslationFileGlueError::NO_LANGUAGE, $file->targetLanguage);
                continue;
            }
            $job->languageTitle = $language->title;
            $texts = [];
            foreach (TranslationFiles::importTranslations($file, $item->segments) as $segment) {
                switch ($segment->status) {
                    case ImportedSegment::OK:
                        $texts[$segment->key] = (string)$segment->text;
                        break;
                    case ImportedSegment::SOURCE_CHANGED:
                        if ($includeChanged) {
                            $texts[$segment->key] = (string)$segment->text;
                        } else {
                            $job->sourceChanged++;
                        }
                        break;
                    case ImportedSegment::UNTRANSLATED:
                        $job->untranslated++;
                        break;
                    case ImportedSegment::INVALID_MARKUP:
                        $job->invalid++;
                        break;
                    default:
                        $job->missing++;
                }
            }
            if ($texts !== []) {
                $job->messages = $this->store->write($item->page, $language->id, $texts);
                $job->imported = count($texts);
            }
        }
        return $results;
    }
}
