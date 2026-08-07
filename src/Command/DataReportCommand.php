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
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Reports how much data the bundle currently stores.
 *
 * The command is strictly read-only. It exists so an operator can see what
 * would be retained before disabling or removing the package: this bundle never
 * drops its tables automatically, and removing the Composer package leaves all
 * multilingual data untouched.
 */
#[AsCommand(
    name: 'contao-multilingual-pagetree:data-report',
    description: 'Reports the multilingual data this bundle stores (read-only).',
)]
final class DataReportCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationFieldRegistry $fields,
        private readonly ?ContaoFramework $framework = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->framework?->initialize();

            $counts = $this->counts();

            if ('json' === strtolower((string) $input->getOption('format'))) {
                $output->writeln((string) json_encode(
                    ['tables' => $counts, 'total' => array_sum($counts)],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));

                return Command::SUCCESS;
            }

            $io->title('Contao Multilingual Pagetree data report');

            if ([] === $counts) {
                $io->success('No multilingual data is stored yet.');

                return Command::SUCCESS;
            }

            $io->table(['Storage', 'Records'], array_map(
                static fn (string $label, int $count): array => [$label, $count],
                array_keys($counts),
                array_values($counts),
            ));

            $io->note(
                'Removing the Composer package does not delete any of these records. '
                .'Deleting multilingual data is always an explicit, separate action.'
            );

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error('The data report could not be produced: '.$exception->getMessage());

            return 3;
        }
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        $schemaManager = $this->connection->createSchemaManager();

        foreach ($this->tables() as $label => $query) {
            [$table, $sql, $parameters] = $query;

            if (!$schemaManager->tablesExist([$table])) {
                continue;
            }

            try {
                $counts[$label] = (int) $this->connection->fetchOne($sql, $parameters);
            } catch (\Throwable) {
                continue;
            }
        }

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    /**
     * Table names come from the field-policy registry and fixed constants only.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function tables(): array
    {
        $queries = [
            'Language configuration' => ['tl_inline_language', 'SELECT COUNT(*) FROM tl_inline_language', []],
        ];

        foreach ($this->fields->policies() as $policy) {
            if ('' === $policy->translationTable) {
                continue;
            }

            $queries[$policy->entityType.' translations'] = [
                $policy->translationTable,
                sprintf('SELECT COUNT(*) FROM %s', $policy->translationTable),
                [],
            ];
        }

        foreach (['tl_article' => 'Free articles', 'tl_content' => 'Free content elements'] as $table => $label) {
            $queries[$label] = [
                $table,
                sprintf('SELECT COUNT(*) FROM %s WHERE %s != :empty', $table, ContentOwnership::FIELD_LANGUAGE),
                ['empty' => ''],
            ];
        }

        return $queries;
    }
}
