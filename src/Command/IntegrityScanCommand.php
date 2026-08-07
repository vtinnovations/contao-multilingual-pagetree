<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


declare(strict_types=1);

namespace Vtinnovations\ContaoMultilingualPagetree\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScanner;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegritySeverity;

/**
 * Read-only integrity scan.
 *
 * The command never writes: it reports issues and returns an exit code that
 * reflects the highest severity found. Output contains codes, tables, ids and
 * counts only - never translated content.
 */
#[AsCommand(
    name: 'contao-multilingual-pagetree:integrity:scan',
    description: 'Scans multilingual records for integrity issues (read-only).',
)]
final class IntegrityScanCommand extends Command
{
    public function __construct(
        private readonly IntegrityScanner $scanner,
        private readonly ?ContaoFramework $framework = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Limit the scan to one root page id')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Limit the scan to one language code')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Limit the scan to one entity type')
            ->addOption('severity', null, InputOption::VALUE_REQUIRED, 'Only report this severity or higher', 'info')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->framework?->initialize();

            $root = (int) $input->getOption('root');
            $language = $this->stringOption($input->getOption('language'));
            $entity = $this->stringOption($input->getOption('entity'));
            $minimum = IntegritySeverity::fromValue($input->getOption('severity'));
            $json = 'json' === strtolower((string) $input->getOption('format'));

            $scope = $root > 0
                ? IntegrityScope::root($root, $language, $entity)
                : IntegrityScope::installation($language, $entity);

            $report = $this->scanner->scan($scope);
            $issues = $report->issues->filterSeverity($minimum);

            if ($json) {
                $output->writeln((string) json_encode(
                    ['scope' => $scope->toArray(), 'total' => $issues->count(), 'issues' => $issues->toArray()],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                ));
            } else {
                $this->renderText($io, $report->scope, $issues);
            }

            if ([] !== $report->failedRules) {
                $io->warning(sprintf('%d rule(s) failed and were skipped.', count($report->failedRules)));
            }

            return $issues->isEmpty() ? Command::SUCCESS : ($this->hasBlocking($issues) ? 2 : 1);
        } catch (\Throwable $exception) {
            $io->error('The integrity scan could not be completed: '.$exception->getMessage());

            return 3;
        }
    }

    private function hasBlocking(\Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->severity->blocksExitCode()) {
                return true;
            }
        }

        return false;
    }

    private function renderText(
        SymfonyStyle $io,
        IntegrityScope $scope,
        \Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection $issues,
    ): void {
        $io->title('Contao Multilingual Pagetree integrity scan');
        $io->text(sprintf('Scope: %s (root %d)', $scope->type, $scope->rootPageId));

        if ($issues->isEmpty()) {
            $io->success('No integrity issues found.');

            return;
        }

        $rows = [];

        foreach ($issues as $issue) {
            $rows[] = [
                $issue->severity->value,
                $issue->code,
                $issue->entityType,
                $issue->table,
                $issue->recordId,
                $issue->language,
                $issue->repairability,
            ];
        }

        $io->table(['Severity', 'Code', 'Entity', 'Table', 'Record', 'Language', 'Repair'], $rows);
        $io->text(sprintf('Total: %d', $issues->count()));

        foreach ($issues->countsBySeverity() as $severity => $count) {
            if ($count > 0) {
                $io->text(sprintf('  %s: %d', $severity, $count));
            }
        }
    }

    private function stringOption(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
