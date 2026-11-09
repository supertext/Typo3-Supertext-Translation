<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Files;

use Supertext\Typo3Translation\Sdk\Files\FileSegment;
use Supertext\Typo3Translation\Service\FieldCollector;
use Supertext\Typo3Translation\Service\LanguageResolver;
use Supertext\Typo3Translation\Service\TranslationService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The TYPO3 side of TranslationFileGlue. An item is a page in the default language with its
 * content elements and their file references (what localizing the page in the Page module sends
 * to Supertext); segment keys are `<table>:<uid>:<field>` of these default-language records, the
 * fields chosen by the same FieldCollector as API translations.
 *
 * Import localizes the records that have no translation in the target language yet (page first,
 * as TYPO3 does; new translations start hidden) with our hook kept out, then writes the texts
 * through TranslationService::writeBack(). DataHandler runs as the current backend user, so its
 * permission checks apply.
 */
final class Typo3TranslationFileStore implements TranslationFileStore
{
    /** Order of localizing: page, content elements, then file references (children of both). */
    private const TABLES = ['pages', 'tt_content', 'sys_file_reference'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly FieldCollector $fieldCollector,
        private readonly TranslationService $translationService,
    ) {}

    public function item(int $page): ?FileItem
    {
        $row = BackendUtility::getRecord('pages', $page);
        if (!is_array($row) || (int)($row['sys_language_uid'] ?? 0) !== 0) {
            return null;
        }
        // Editors (backend module) only get pages they may see; admins and the CLI user see all.
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof BackendUserAuthentication && !$user->isAdmin()
            && !is_array(BackendUtility::readPageAccess($page, $user->getPagePermsClause(Permission::PAGE_SHOW)))) {
            return null;
        }
        $segments = [];
        foreach ($this->records($page, $row) as [$table, $record]) {
            foreach ($this->fieldCollector->collect($table, $record) as $field => $isHtml) {
                $segments[] = new FileSegment($table . ':' . (int)$record['uid'] . ':' . $field, (string)$record[$field], $isHtml);
            }
        }
        return new FileItem($page, (string)($row['title'] ?? ''), $segments);
    }

    public function language(int $page, int $language): ?FileLanguage
    {
        if ($language <= 0) {
            return null;
        }
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof BackendUserAuthentication && !$user->checkLanguageAccess($language)) {
            return null;
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($page);
            $target = $site->getLanguageById($language);
        } catch (\Throwable) {
            return null;
        }
        $default = $site->getDefaultLanguage();
        return new FileLanguage(
            $language,
            $target->getTitle(),
            LanguageResolver::localeOf($default),
            LanguageResolver::overrideOf($default),
            LanguageResolver::localeOf($target),
            LanguageResolver::overrideOf($target),
        );
    }

    public function write(int $page, int $language, array $texts): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return ['No backend user.'];
        }
        // Source records per table that get a text.
        $sources = [];
        foreach (array_keys($texts) as $key) {
            [$table, $uid] = explode(':', $key, 3) + ['', '0'];
            $sources[$table][(int)$uid] = true;
        }
        $sources['pages'][$page] = true;
        $errors = [];
        $translations = [];
        foreach (self::TABLES as $table) {
            foreach (array_keys($sources[$table] ?? []) as $uid) {
                $translated = $this->translationOf($table, $uid, $language);
                if ($translated === null) {
                    $errors = array_merge($errors, $this->localize($table, $uid, $language, $user));
                    $translated = $this->translationOf($table, $uid, $language);
                }
                if ($translated !== null) {
                    $translations[$table][$uid] = $translated;
                }
            }
        }
        $datamap = [];
        foreach ($texts as $key => $text) {
            [$table, $uid, $field] = explode(':', $key, 3) + ['', '0', ''];
            $translated = $translations[$table][(int)$uid] ?? null;
            if ($translated !== null && $field !== '') {
                $datamap[$table][$translated][$field] = $text;
            }
        }
        return array_values(array_unique(array_merge($errors, $this->translationService->writeBack($datamap, $user))));
    }

    /**
     * The page record, its content elements and their file references (default language, not deleted;
     * hidden ones too, as localizing copies them).
     *
     * @param array<string, mixed> $pageRow
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function records(int $page, array $pageRow): array
    {
        $records = [['pages', $pageRow]];
        foreach ($this->fileReferences('pages', $page) as $reference) {
            $records[] = ['sys_file_reference', $reference];
        }
        foreach ($this->select('tt_content', ['pid' => $page, 'sys_language_uid' => 0], 'sorting') as $content) {
            $records[] = ['tt_content', $content];
            foreach ($this->fileReferences('tt_content', (int)$content['uid']) as $reference) {
                $records[] = ['sys_file_reference', $reference];
            }
        }
        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function fileReferences(string $table, int $uid): array
    {
        return $this->select('sys_file_reference', ['uid_foreign' => $uid, 'tablenames' => $table, 'sys_language_uid' => 0], 'sorting_foreign');
    }

    /**
     * @param array<string, int|string> $where
     * @return list<array<string, mixed>>
     */
    private function select(string $table, array $where, string $orderBy): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $qb->select('*')->from($table);
        foreach ($where as $field => $value) {
            $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR)));
        }
        return $qb->orderBy($orderBy)->executeQuery()->fetchAllAssociative();
    }

    /** The uid of the record's translation in the language (not deleted), null when there is none. */
    private function translationOf(string $table, int $uid, int $language): ?int
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        $languageField = (string)($ctrl['languageField'] ?? '');
        if ($parentField === '' || $languageField === '') {
            return null;
        }
        $rows = $this->select($table, [$parentField => $uid, $languageField => $language], 'uid');
        return $rows !== [] ? (int)$rows[0]['uid'] : null;
    }

    /** @return list<string> DataHandler errors */
    private function localize(string $table, int $uid, int $language, BackendUserAuthentication $user): array
    {
        TranslationService::$running = true;
        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], [$table => [$uid => ['localize' => $language]]], $user);
            $dataHandler->process_cmdmap();
            return array_map('strval', $dataHandler->errorLog);
        } finally {
            TranslationService::$running = false;
        }
    }
}
