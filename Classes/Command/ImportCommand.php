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
 * vendor/bin/typo3 supertext:import <file> [--include-changed]
 *
 * Imports a translated XLIFF or HTML file exported by supertext:export into the page's
 * translation and prints a summary per page and language.
 */
final class ImportCommand extends Command
{
    public function __construct(private readonly TranslationFileGlue $glue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Translated file (XLIFF or HTML), at most 10 MB')
            ->addOption('include-changed', null, InputOption::VALUE_NONE, 'Also import texts whose source changed since the export');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        $labels = Labels::forUser($GLOBALS['BE_USER'] ?? null);
        try {
            $jobs = $this->glue->importFile((string)$input->getArgument('file'), (bool)$input->getOption('include-changed'));
        } catch (TranslationFileGlueError|TranslationFileError $e) {
            $output->writeln('<error>' . $labels->forFileError($e) . '</error>');
            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }
        $failed = false;
        foreach ($jobs as $job) {
            $failed = $failed || $job->error !== null || $job->messages !== [];
            foreach ($labels->importSummary($job) as $i => $line) {
                $output->writeln($i === 0 ? '<info>' . $line . '</info>' : '  ' . $line);
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
