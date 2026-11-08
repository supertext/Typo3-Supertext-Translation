<?php

declare(strict_types=1);

namespace Supertext\Typo3Translation\Command;

use Supertext\Typo3Translation\Files\TranslationFileGlue;
use Supertext\Typo3Translation\Files\TranslationFileGlueError;
use Supertext\Typo3Translation\Localization\Labels;
use Supertext\Typo3Translation\Sdk\Files\TranslationFileError;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * vendor/bin/typo3 supertext:export <page> <language...> [--format=xliff|html] [--output=<file>]
 *
 * Exports a page and its content elements for translation outside the API (XLIFF 1.2 or HTML).
 */
final class ExportCommand extends Command
{
    public function __construct(private readonly TranslationFileGlue $glue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('page', InputArgument::REQUIRED, 'Page uid (default language)')
            ->addArgument('language', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Target site language id(s); HTML: exactly one')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'xliff (XLIFF 1.2, for translation tools) or html (one language per file)', TranslationFileGlue::FORMAT_XLIFF)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write (default: typo3-<page>-<target>.xlf in the current directory)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        $labels = Labels::forUser($GLOBALS['BE_USER'] ?? null);
        $languages = array_map('intval', (array)$input->getArgument('language'));
        try {
            $file = $this->glue->export((int)$input->getArgument('page'), $languages, strtolower((string)$input->getOption('format')));
        } catch (TranslationFileGlueError|TranslationFileError $e) {
            $output->writeln('<error>' . $labels->forFileError($e) . '</error>');
            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }
        $path = (string)($input->getOption('output') ?? '');
        if ($path === '') {
            $path = $file->fileName;
        }
        if (@file_put_contents($path, $file->content) === false) {
            $output->writeln('<error>Cannot write ' . $path . '</error>');
            return Command::FAILURE;
        }
        $output->writeln($labels->get('file.saved', $path));
        return Command::SUCCESS;
    }
}
