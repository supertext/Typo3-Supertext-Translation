<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Service;

use Psr\Log\LoggerInterface;
use Supertext\Typo3Translation\Api\SupertextClientFactory;
use Supertext\Typo3Translation\Configuration\ExtensionSettings;
use Supertext\Typo3Translation\Localization\Labels;
use Supertext\Typo3Translation\Reporting\Reporting;
use Supertext\Typo3Translation\Reporting\Typo3Reporting;
use Supertext\Typo3Translation\Sdk\V1\Segment;
use Supertext\Typo3Translation\Sdk\V1\SupertextException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translates freshly localized records with Supertext and writes the result back.
 *
 * All records of one DataHandler run that share a target language go to Supertext
 * as ONE document (the Supertext SDK splits only beyond ~900k characters), so
 * translating a page with twenty content elements is a single API round trip.
 */
final class TranslationService
{
    /** Set while we write translations back, so our own DataHandler run is ignored. */
    public static bool $running = false;

    public function __construct(
        private readonly SupertextClientFactory $clientFactory,
        private readonly FieldCollector $fieldCollector,
        private readonly LanguageResolver $languageResolver,
        private readonly ExtensionSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly Typo3Reporting $reporting,
    ) {}

    /**
     * @param list<array{table: string, source: int, target: int, language: int}> $jobs
     */
    public function translate(array $jobs, BackendUserAuthentication $backendUser): TranslationResult
    {
        $result = new TranslationResult();
        $labels = Labels::forUser($backendUser);
        // Version report / key check after the response (Commando Center); never blocks.
        $this->reporting->later();
        if (!$this->clientFactory->hasApiKey()) {
            $result->errors[] = $labels->get('error.noApiKey');
            return $result;
        }

        // Group segments by language pair, so each pair is one document.
        $groups = [];
        $verbatim = [];
        foreach ($jobs as $job) {
            $source = BackendUtility::getRecord($job['table'], $job['source']);
            $target = BackendUtility::getRecord($job['table'], $job['target']);
            if (!is_array($source) || !is_array($target)) {
                continue;
            }
            $pageId = $job['table'] === 'pages' ? $job['source'] : (int)$source['pid'];
            try {
                $language = $this->languageResolver->resolve($pageId, $job['language']);
            } catch (SupertextException $e) {
                // The language has no Supertext code: nothing is sent; one message per language.
                $title = $this->languageResolver->titleOf($pageId, $job['language']);
                $result->errors[] = ($title !== '' ? $title . ': ' : '') . $labels->forException($e);
                continue;
            }
            if ($language === null) {
                $result->errors[] = $labels->get('error.noSiteLanguage', $job['table'], $job['source'], $job['language']);
                continue;
            }
            $key = $language['target'] . '|' . $language['source'] . '|' . $language['politeness'] . '|' . ($language['review'] ? 'review' : '');
            $groups[$key]['language'] ??= $language;
            foreach ($this->fieldCollector->collect($job['table'], $source) as $field => $isHtml) {
                $groups[$key]['segments'][] = [
                    'table' => $job['table'],
                    'uid' => $job['target'],
                    'field' => $field,
                    'text' => (string)$source[$field],
                    'html' => $isHtml,
                ];
            }
            $groups[$key]['records'][$job['table'] . ':' . $job['target']] = $target;
            // Code fields we deliberately skip (e.g. HTML elements) keep their source
            // verbatim, without TYPO3's "[Translate to …:]" prefix breaking the code.
            if ($job['table'] === 'tt_content'
                && in_array((string)($source['CType'] ?? ''), $this->settings->getSkipBodytextCTypes(), true)
                && (string)($target['bodytext'] ?? '') !== (string)($source['bodytext'] ?? '')
            ) {
                $verbatim['tt_content'][$job['target']]['bodytext'] = (string)$source['bodytext'];
            }
        }

        $datamap = $verbatim;
        // One client for direct translations, one for languages with "Translation review" on.
        $clients = [];
        foreach ($groups as $group) {
            if (empty($group['segments'])) {
                continue;
            }
            $language = $group['language'];
            $texts = [];
            foreach ($group['segments'] as $i => $segment) {
                $texts[$i] = new Segment($segment['text'], $segment['html']);
            }
            try {
                $client = $clients[$language['review'] ? 1 : 0] ??= $this->clientFactory->create($language['review']);
                $translated = $client->translateTexts($texts, $language['target'], $language['source'], $language['politeness']);
            } catch (\Throwable $e) {
                $this->logger->error('Supertext translation failed', ['exception' => $e, 'language' => $language['target']]);
                $result->errors[] = sprintf('%s: %s', $language['title'], $labels->forException($e));
                if ($e instanceof SupertextException && $e->errorCode === SupertextException::AUTHENTICATION_FAILURE) {
                    $this->reporting->later(Reporting::ACTION_AUTHENTICATION_FAILED, $e->status ?? 401);
                }
                continue;
            }
            foreach ($group['segments'] as $i => $segment) {
                if (trim($translated[$i] ?? '') === '') {
                    continue;
                }
                $datamap[$segment['table']][$segment['uid']][$segment['field']] = $translated[$i];
                $result->fields++;
                $result->records[$segment['table'] . ':' . $segment['uid']] = true;
            }
            $result->languages[$language['title']] = $language['target'];
            if ($this->settings->shouldRegenerateSlugs()) {
                $this->addSlugs($datamap, $group['records']);
            }
        }

        if ($datamap !== []) {
            $this->write($datamap, $backendUser, $result);
        }
        $result->errors = array_values(array_unique($result->errors));
        return $result;
    }

    /**
     * Writes translations into existing translated records (file import, TranslationFileGlue)
     * with the same rules as API results: one DataHandler run as the user, page slugs from the
     * translated title (if configured), our own hook kept out.
     *
     * @param array<string, array<int, array<string, string>>> $datamap table => translated uid => field => text
     * @return list<string> DataHandler errors
     */
    public function writeBack(array $datamap, BackendUserAuthentication $backendUser): array
    {
        $result = new TranslationResult();
        if ($this->settings->shouldRegenerateSlugs()) {
            $records = [];
            foreach (array_keys($datamap['pages'] ?? []) as $uid) {
                $record = BackendUtility::getRecord('pages', $uid);
                if (is_array($record)) {
                    $records['pages:' . $uid] = $record;
                }
            }
            $this->addSlugs($datamap, $records);
        }
        if ($datamap !== []) {
            $this->write($datamap, $backendUser, $result);
        }
        return $result->errors;
    }

    /**
     * Builds the URL segment of translated pages from their translated title.
     *
     * @param array<string, array<int, array<string, string>>> $datamap
     * @param array<string, array<string, mixed>> $records
     */
    private function addSlugs(array &$datamap, array $records): void
    {
        $slugConfig = $GLOBALS['TCA']['pages']['columns']['slug']['config'] ?? null;
        if (!is_array($slugConfig)) {
            return;
        }
        foreach ($datamap['pages'] ?? [] as $uid => $values) {
            $record = $records['pages:' . $uid] ?? null;
            if (!is_array($record) || !isset($values['title'])) {
                continue;
            }
            try {
                $record = array_merge($record, $values);
                $helper = GeneralUtility::makeInstance(SlugHelper::class, 'pages', 'slug', $slugConfig);
                $slug = $helper->generate($record, (int)$record['pid']);
                $state = RecordStateFactory::forName('pages')->fromArray($record, (int)$record['pid'], (int)$uid);
                $datamap['pages'][$uid]['slug'] = $helper->buildSlugForUniqueInSite($slug, $state);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not regenerate slug', ['uid' => $uid, 'exception' => $e]);
            }
        }
    }

    /** @param array<string, array<int, array<string, string>>> $datamap */
    private function write(array $datamap, BackendUserAuthentication $backendUser, TranslationResult $result): void
    {
        self::$running = true;
        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($datamap, [], $backendUser);
            $dataHandler->process_datamap();
            foreach ($dataHandler->errorLog as $error) {
                $result->errors[] = (string)$error;
            }
        } finally {
            self::$running = false;
        }
    }
}
