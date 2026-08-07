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

namespace Vtinnovations\ContaoMultilingualPagetree\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\LegacyValueComparator;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

final class FieldStatesMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationFieldRegistry $fields,
        private readonly FieldStateMap $states,
        private readonly LegacyValueComparator $comparator,
    ) {
    }

    public function getName(): string
    {
        \Contao\System::loadLanguageFile('default');

        return (string) ($GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFieldStatesMigration'] ?? self::class);
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        foreach ($this->fields->tables() as $table) {
            if (!$schema->tablesExist([$table])) {
                continue;
            }
            $columns = $schema->listTableColumns($table);
            if (!isset($columns['fieldstates'])) {
                continue;
            }
            $count = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM {$table} WHERE fieldStates IS NULL OR fieldStates = '' OR fieldStates = '{}'",
            );
            if ($count > 0) {
                return true;
            }
        }

        return false;
    }

    public function run(): MigrationResult
    {
        $schema = $this->connection->createSchemaManager();
        $updated = 0;

        foreach ($this->fields->tables() as $translationTable) {
            $sourceTable = $this->fields->sourceTable($translationTable);
            if ($sourceTable === null || !$schema->tablesExist([$translationTable, $sourceTable])) {
                continue;
            }

            $translationColumns = $schema->listTableColumns($translationTable);
            $sourceColumns = $schema->listTableColumns($sourceTable);
            if (!isset($translationColumns['fieldstates'])) {
                continue;
            }

            $supportedFields = array_values(array_filter(
                $this->fields->fieldNames(
                    $translationTable,
                    array_map(static fn ($column): string => $column->getName(), $translationColumns),
                ),
                static fn (string $field): bool => isset($translationColumns[strtolower($field)], $sourceColumns[strtolower($field)]),
            ));
            if ($supportedFields === []) {
                continue;
            }

            $select = ['t.id', 't.fieldStates'];
            foreach ($supportedFields as $field) {
                $select[] = "t.{$field} AS translated_{$field}";
                $select[] = "s.{$field} AS source_{$field}";
            }

            $records = $this->connection->fetchAllAssociative(
                'SELECT '.implode(', ', $select)." FROM {$translationTable} t INNER JOIN {$sourceTable} s ON s.id = t.pid "
                ."WHERE t.fieldStates IS NULL OR t.fieldStates = '' OR t.fieldStates = '{}'",
            );

            foreach ($records as $record) {
                // A non-empty map is editor-owned and is never changed by this migration.
                if ($this->states->decode($record['fieldStates'] ?? null) !== []) {
                    continue;
                }

                $map = [];
                foreach ($supportedFields as $field) {
                    $translated = $record['translated_'.$field] ?? null;
                    $source = $record['source_'.$field] ?? null;
                    $map[$field] = $this->comparator->isAmbiguouslyEmpty($translated)
                        || $this->comparator->equivalent($translated, $source)
                        ? FieldStateMap::INHERIT
                        : FieldStateMap::CUSTOM;
                }

                $this->connection->update(
                    $translationTable,
                    ['fieldStates' => $this->states->encode($map)],
                    ['id' => $record['id']],
                );
                ++$updated;
            }
        }

        \Contao\System::loadLanguageFile('default');
        $message = (string) ($GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFieldStatesMigrated'] ?? '%d');

        return $this->createResult(true, sprintf($message, $updated));
    }
}
