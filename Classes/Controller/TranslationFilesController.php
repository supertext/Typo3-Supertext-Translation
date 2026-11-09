<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Controller;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Supertext\Typo3Translation\Files\ExportedFile;
use Supertext\Typo3Translation\Files\TranslationFileGlue;
use Supertext\Typo3Translation\Files\TranslationFileGlueError;
use Supertext\Typo3Translation\Localization\Labels;
use Supertext\Typo3Translation\Sdk\Files\TranslationFileError;
use Supertext\Typo3Translation\Service\LanguageResolver;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend module Content (Web) → Supertext: export the page selected in the page tree for
 * translation (XLIFF 1.2 or HTML, downloaded), import a translated file (any page, uploaded and
 * read in memory, never stored). Same TranslationFileGlue as supertext:export / supertext:import;
 * the import writes through DataHandler as the current backend user, so their page and language
 * permissions apply. Forms post to the module URL, which carries TYPO3's route token.
 */
#[AsController]
final class TranslationFilesController
{
    public const MODULE = 'supertext_translation_files';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly TranslationFileGlue $glue,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'];
        \assert($user instanceof BackendUserAuthentication);
        $labels = Labels::forUser($user);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($labels->get('module.title'));

        $query = $request->getQueryParams();
        $body = (array)($request->getParsedBody() ?? []);
        $pageId = (int)($body['id'] ?? $query['id'] ?? 0);
        $page = $this->page($pageId, $user);
        $languages = $page !== null ? $this->languages($pageId, $user) : [];
        if ($page !== null) {
            $view->getDocHeaderComponent()->setMetaInformation($page);
        }

        $selected = [];
        $format = TranslationFileGlue::FORMAT_XLIFF;
        $includeChanged = false;
        $jobs = null;

        if ($request->getMethod() === 'POST') {
            $action = (string)($body['action'] ?? '');
            if ($action === 'export') {
                $selected = array_values(array_intersect(
                    array_map('intval', (array)($body['languages'] ?? [])),
                    array_column($languages, 'id'),
                ));
                $format = ($body['format'] ?? '') === TranslationFileGlue::FORMAT_HTML ? TranslationFileGlue::FORMAT_HTML : TranslationFileGlue::FORMAT_XLIFF;
                $error = $this->exportError($page, $selected, $format, $labels);
                if ($error === null) {
                    try {
                        return $this->download($this->glue->export((int)$page['uid'], $selected, $format));
                    } catch (TranslationFileGlueError|TranslationFileError $e) {
                        $error = $labels->forFileError($e);
                    }
                }
                $view->addFlashMessage($error, $labels->get('files.export'), ContextualFeedbackSeverity::ERROR, false);
            } elseif ($action === '' && $request->getUploadedFiles() === [] && (int)($request->getServerParams()['CONTENT_LENGTH'] ?? 0) > 0) {
                // PHP drops the whole body of a request over post_max_size: only an upload does that here.
                $view->addFlashMessage($labels->get('file.error.tooLarge'), $labels->get('files.import'), ContextualFeedbackSeverity::ERROR, false);
            } elseif ($action === 'import') {
                $includeChanged = !empty($body['includeChanged']);
                $jobs = $this->import($request->getUploadedFiles()['file'] ?? null, $includeChanged, $labels, $view);
            }
        }

        $view->assignMultiple([
            'labels' => $this->viewLabels($labels) + [
                'exportHint' => $page !== null ? $labels->get('files.exportHint', (string)$page['title']) : '',
            ],
            'actionUri' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE, ['id' => $pageId]),
            'pageId' => $pageId,
            'page' => $page,
            'languages' => array_map(static fn(array $l): array => $l + ['checked' => in_array($l['id'], $selected, true)], $languages),
            'format' => $format,
            'includeChanged' => $includeChanged,
            'jobs' => $jobs,
            'maxBytes' => TranslationFileGlue::MAX_UPLOAD_BYTES,
        ]);
        return $view->renderResponse('TranslationFiles/Index');
    }

    /**
     * The page record when it can be exported: a default-language page the user may see.
     *
     * @return array<string, mixed>|null
     */
    private function page(int $pageId, BackendUserAuthentication $user): ?array
    {
        if ($pageId <= 0) {
            return null;
        }
        $page = BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW));
        if (!is_array($page) || (int)($page['sys_language_uid'] ?? 0) !== 0) {
            return null;
        }
        return $page;
    }

    /**
     * The page's site languages the user may edit, without the default language.
     *
     * @return list<array{id: int, title: string, code: string}>
     */
    private function languages(int $pageId, BackendUserAuthentication $user): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (\Throwable) {
            return [];
        }
        $languages = [];
        foreach ($site->getAvailableLanguages($user, false, $pageId) as $language) {
            if ($language->getLanguageId() <= 0) {
                continue;
            }
            $languages[] = [
                'id' => $language->getLanguageId(),
                'title' => $language->getTitle(),
                'code' => TranslationFileGlue::code(LanguageResolver::localeOf($language), LanguageResolver::overrideOf($language), false),
            ];
        }
        return $languages;
    }

    /**
     * @param array<string, mixed>|null $page
     * @param list<int> $selected
     */
    private function exportError(?array $page, array $selected, string $format, Labels $labels): ?string
    {
        if ($page === null) {
            return $labels->get('files.selectPage');
        }
        if ($selected === []) {
            return $labels->get('files.chooseLanguage');
        }
        if ($format === TranslationFileGlue::FORMAT_HTML && count($selected) !== 1) {
            return $labels->get('files.htmlOneLanguage');
        }
        return null;
    }

    private function download(ExportedFile $file): ResponseInterface
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $file->fileName) ?: 'typo3.xlf';
        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', $file->contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Content-Length', (string)strlen($file->content))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streamFactory->createStream($file->content));
    }

    /**
     * @return list<array{heading: string, lines: list<string>, state: string}>|null null: nothing imported (the problem is in a flash message)
     */
    private function import(mixed $file, bool $includeChanged, Labels $labels, ModuleTemplate $view): ?array
    {
        $title = $labels->get('files.import');
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $view->addFlashMessage($labels->get('files.noFile'), $title, ContextualFeedbackSeverity::WARNING, false);
            return null;
        }
        // Too large: refused before reading (also when PHP's own upload limit cut it off).
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || (int)$file->getSize() > TranslationFileGlue::MAX_UPLOAD_BYTES) {
            $view->addFlashMessage($labels->get('file.error.tooLarge'), $title, ContextualFeedbackSeverity::ERROR, false);
            return null;
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $view->addFlashMessage($labels->get('files.uploadFailed'), $title, ContextualFeedbackSeverity::ERROR, false);
            return null;
        }
        try {
            $imported = $this->glue->import($file->getStream()->getContents(), $includeChanged);
        } catch (TranslationFileGlueError|TranslationFileError $e) {
            $view->addFlashMessage($labels->forFileError($e), $title, ContextualFeedbackSeverity::ERROR, false);
            return null;
        }
        $jobs = [];
        foreach ($imported as $job) {
            $lines = $labels->importSummary($job);
            $jobs[] = [
                'heading' => (string)array_shift($lines),
                'lines' => $lines,
                'state' => $job->error !== null || $job->messages !== [] ? 'danger' : ($job->imported > 0 ? 'success' : 'info'),
            ];
        }
        return $jobs;
    }

    /** @return array<string, string> */
    private function viewLabels(Labels $labels): array
    {
        $keys = [
            'title' => 'module.title',
            'export' => 'files.export',
            'selectPage' => 'files.selectPage',
            'noLanguages' => 'files.noLanguages',
            'languages' => 'files.languages',
            'format' => 'files.format',
            'formatXliff' => 'files.formatXliff',
            'formatHtml' => 'files.formatHtml',
            'download' => 'files.download',
            'import' => 'files.import',
            'importHint' => 'files.importHint',
            'file' => 'files.file',
            'includeChanged' => 'files.includeChanged',
            'importButton' => 'files.importButton',
            'result' => 'files.result',
        ];
        return array_map(static fn(string $key): string => $labels->get($key), $keys);
    }
}
