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
use Vtinnovations\ContaoMultilingualPagetree\Schema\BundleSchema;

/**
 * Adds the lookup indexes the integrity subsystem and the runtime queries rely
 * on.
 *
 * Only non-unique indexes are created: a uniqueness constraint on
 * (pid, language) would fail on installations that still contain duplicate
 * translations, so duplicates are reported by the integrity scanner and must be
 * resolved by an editor first. The migration never deletes or changes data and
 * skips every index that already exists, so repeated runs do nothing.
 */
final class IntegrityIndexMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return $this->label('contaoMultilingualPagetreeIntegrityIndexMigration', self::class);
    }

    public function shouldRun(): bool
    {
        return [] !== $this->missingIndexes();
    }

    public function run(): MigrationResult
    {
        $created = 0;

        foreach ($this->missingIndexes() as $definition) {
            try {
                $this->connection->executeStatement(sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $definition['name'],
                    $definition['table'],
                    implode(', ', $definition['columns']),
                ));
                ++$created;
            } catch (\Throwable) {
                // A concurrent migration or an unsupported engine must not make
                // the migration fail permanently.
                continue;
            }
        }

        return $this->createResult(true, sprintf($this->label('contaoMultilingualPagetreeIntegrityIndexMigrated', '%d'), $created));
    }

    /**
     * @return list<array{table: string, name: string, columns: list<string>}>
     */
    private function missingIndexes(): array
    {
        $missing = [];

        try {
            $schemaManager = $this->connection->createSchemaManager();
        } catch (\Throwable) {
            return [];
        }

        foreach (BundleSchema::INTEGRITY_INDEXES as $definition) {
            try {
                if (!$schemaManager->tablesExist([$definition['table']])) {
                    continue;
                }

                $columns = $schemaManager->listTableColumns($definition['table']);

                foreach ($definition['columns'] as $column) {
                    if (!isset($columns[strtolower($column)])) {
                        continue 2;
                    }
                }

                foreach ($schemaManager->listTableIndexes($definition['table']) as $index) {
                    // Structural corrections of an occupied name belong to the
                    // authoritative Contao schema diff. This legacy repair
                    // migration only creates genuinely absent indexes and
                    // therefore never needs a destructive DROP operation.
                    if (strtolower($index->getName()) === strtolower($definition['name'])) {
                        continue 2;
                    }
                }

                $missing[] = $definition;
            } catch (\Throwable) {
                continue;
            }
        }

        return $missing;
    }

    private function label(string $key, string $default): string
    {
        try {
            \Contao\System::loadLanguageFile('default');
        } catch (\Throwable) {
            // The migration must remain usable without a booted framework.
        }

        $label = $GLOBALS['TL_LANG']['MSC'][$key] ?? null;

        return is_string($label) && '' !== $label ? $label : $default;
    }
}
