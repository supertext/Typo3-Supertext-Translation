<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Localization;

use Supertext\Typo3Translation\Files\ImportedJob;
use Supertext\Typo3Translation\Files\TranslationFileGlueError;
use Supertext\Typo3Translation\Sdk\Files\TranslationFileError;
use Supertext\Typo3Translation\Sdk\V1\SupertextException;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Messages shown to editors, in the backend user's interface language
 * (Resources/Private/Language/{,de.,fr.,it.}locallang.xlf; English is the fallback).
 */
final class Labels
{
    public const FILE = 'LLL:EXT:supertext_translation/Resources/Private/Language/locallang.xlf:';

    public function __construct(private readonly LanguageService $languageService) {}

    public static function forUser(?AbstractUserAuthentication $user): self
    {
        return new self(GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($user));
    }

    public function get(string $key, string|int ...$arguments): string
    {
        $label = $this->languageService->sL(self::FILE . $key);
        if ($label === '') {
            $label = $key;
        }
        return $arguments === [] ? $label : vsprintf($label, $arguments);
    }

    /** Label key per Supertext SDK error code (SupertextException::$errorCode). */
    private const ERROR_LABELS = [
        SupertextException::MISSING_API_KEY => 'error.noApiKey',
        SupertextException::AUTHENTICATION_FAILURE => 'error.authentication',
        SupertextException::INVALID_LANGUAGE_PAIR => 'error.languagePair',
        SupertextException::UNSUPPORTED_LANGUAGE => 'error.unsupportedLanguage',
        SupertextException::UNSUPPORTED_FEATURE => 'error.unsupportedFeature',
        SupertextException::NOT_FOUND => 'error.notFound',
        SupertextException::PAYLOAD_TOO_LARGE => 'error.tooLarge',
        SupertextException::TOO_MANY_REQUESTS => 'error.rateLimit',
        SupertextException::SERVICE_UNAVAILABLE => 'error.unavailable',
        SupertextException::UNEXPECTED_STATUS => 'error.http',
        SupertextException::TRANSPORT_ERROR => 'error.unreachable',
        SupertextException::NO_FILE_ID => 'error.noFileId',
        SupertextException::TRANSLATION_ERROR => 'error.failed',
        SupertextException::QUOTA_EXCEEDED => 'error.limitExceeded',
        SupertextException::FILE_DELETED => 'error.deleted',
        SupertextException::TIMEOUT => 'error.timeout',
        SupertextException::INCOMPLETE_RESPONSE => 'error.empty',
    ];

    /** The translated message of a Supertext SDK error, or the English message of other exceptions. */
    public function forException(\Throwable $e): string
    {
        if (!$e instanceof SupertextException || !isset(self::ERROR_LABELS[$e->errorCode])) {
            return $e->getMessage();
        }
        $detail = trim((string)$e->detail);
        $message = match ($e->errorCode) {
            SupertextException::UNEXPECTED_STATUS => $this->get('error.http', (int)$e->status),
            SupertextException::TRANSPORT_ERROR => $this->get('error.unreachable', $detail),
            SupertextException::UNSUPPORTED_LANGUAGE => $this->get('error.unsupportedLanguage', (string)($e->targetLang ?? $e->sourceLang ?? '')),
            default => $this->get(self::ERROR_LABELS[$e->errorCode]),
        };
        if (in_array($e->errorCode, [SupertextException::TRANSPORT_ERROR, SupertextException::UNSUPPORTED_LANGUAGE], true)) {
            return $message;
        }
        return $detail !== '' ? $message . ' (' . $detail . ')' : $message;
    }

    /** The message of a translation file error (the SDK's TranslationFileError or the extension's TranslationFileGlueError). */
    public function forFileError(\Throwable $e): string
    {
        if ($e instanceof TranslationFileError) {
            return match ($e->errorCode) {
                TranslationFileError::INVALID_FILE => $this->get('file.error.invalidFile'),
                TranslationFileError::MISSING_METADATA => $this->get('file.error.missingMetadata'),
                TranslationFileError::TOO_LARGE => $this->get('file.error.tooLarge'),
                TranslationFileError::INVALID_JOB => $this->get('file.nothing'),
                default => $e->getMessage(),
            };
        }
        if ($e instanceof TranslationFileGlueError) {
            return match ($e->errorCode) {
                TranslationFileGlueError::NOTHING_TO_TRANSLATE => $this->get('file.nothing'),
                TranslationFileGlueError::TOO_LARGE => $this->get('file.error.tooLarge'),
                TranslationFileGlueError::OTHER_SYSTEM => $this->get('file.error.otherSystem', $e->argument),
                TranslationFileGlueError::NOT_FOUND => $this->get('file.error.notFound'),
                TranslationFileGlueError::NO_LANGUAGE => $this->get('file.error.noLanguage', $e->argument),
                default => $e->getMessage(),
            };
        }
        return $e->getMessage();
    }

    /**
     * The summary of one imported job: a heading (page, language) and the counts or its error.
     *
     * @return list<string>
     */
    public function importSummary(ImportedJob $job): array
    {
        $heading = $job->page !== null
            ? $this->get('file.job', $job->page, $job->title, $job->languageTitle !== '' ? $job->languageTitle : $job->targetLanguage)
            : $job->reference . ' (' . $job->targetLanguage . ')';
        if ($job->error !== null) {
            return [$heading, $this->forFileError($job->error)];
        }
        $lines = [$heading, $this->get('file.summary.ok', $job->imported)];
        foreach ([
            'file.summary.changed' => $job->sourceChanged,
            'file.summary.untranslated' => $job->untranslated,
            'file.summary.invalid' => $job->invalid,
            'file.summary.missing' => $job->missing,
        ] as $key => $count) {
            if ($count > 0) {
                $lines[] = $this->get($key, $count);
            }
        }
        return array_merge($lines, $job->messages);
    }
}
