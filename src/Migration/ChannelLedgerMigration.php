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
use Vtinnovations\ContaoMultilingualPagetree\Storage\DatabaseRequestLedger;

/**
 * Creates the shared ledger used for replay protection and idempotency of
 * inbound server-to-server requests.
 *
 * The constructor only stores the connection. Schema access is deferred to
 * shouldRun()/run(), both tolerate an empty database and repeated execution, so
 * discovery stays safe during an initial setup.
 */
final class ChannelLedgerMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Create the Contao Multilingual Pagetree channel request ledger';
    }

    public function shouldRun(): bool
    {
        return [] !== $this->missingSchemaParts();
    }

    public function run(): MigrationResult
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([DatabaseRequestLedger::TABLE])) {
                $this->connection->executeStatement(BundleSchema::createLedgerSql());

                return $this->createResult(true, 'The channel request ledger was created.');
            }

            $parts = $this->missingSchemaParts();

            foreach ($parts['columns'] ?? [] as $column) {
                $this->connection->executeStatement(sprintf(
                    'ALTER TABLE %s ADD %s %s',
                    DatabaseRequestLedger::TABLE,
                    $column,
                    BundleSchema::LEDGER_COLUMNS[$column]['sql'],
                ));
            }

            if ($parts['primary'] ?? false) {
                $this->connection->executeStatement('ALTER TABLE '.DatabaseRequestLedger::TABLE.' ADD PRIMARY KEY ('.implode(', ', BundleSchema::LEDGER_PRIMARY_KEY).')');
            }

            foreach ($parts['indexes'] ?? [] as $index) {
                $this->connection->executeStatement(sprintf(
                    'CREATE %sINDEX %s ON %s (%s)',
                    $index['unique'] ? 'UNIQUE ' : '',
                    $index['name'],
                    $index['table'],
                    implode(', ', $index['columns']),
                ));
            }

            return $this->createResult(true, 'The channel request ledger schema was repaired.');
        } catch (\Throwable $exception) {
            return $this->createResult(false, 'The channel request ledger could not be created: '.$exception->getMessage());
        }
    }

    /**
     * @return array{columns?: list<string>, primary?: bool, indexes?: list<array{table: string, name: string, columns: list<string>, unique: bool}>}
     */
    private function missingSchemaParts(): array
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([DatabaseRequestLedger::TABLE])) {
                return ['columns' => array_keys(BundleSchema::LEDGER_COLUMNS), 'primary' => true, 'indexes' => BundleSchema::LEDGER_INDEXES];
            }

            $actualColumns = $schemaManager->listTableColumns(DatabaseRequestLedger::TABLE);
            $missingColumns = [];

            foreach (array_keys(BundleSchema::LEDGER_COLUMNS) as $column) {
                if (!isset($actualColumns[strtolower($column)])) {
                    $missingColumns[] = $column;
                }
            }

            $actualIndexes = $schemaManager->listTableIndexes(DatabaseRequestLedger::TABLE);
            $hasPrimary = false;

            foreach ($actualIndexes as $index) {
                if ($index->isPrimary()) {
                    $hasPrimary = true;
                    break;
                }
            }

            $missingIndexes = [];
            foreach (BundleSchema::LEDGER_INDEXES as $wanted) {
                $found = false;
                foreach ($actualIndexes as $index) {
                    // A same-named but structurally different index belongs to
                    // Contao's schema diff, which can replace it atomically.
                    // The repair migration never drops or fights that process.
                    if (strtolower($index->getName()) === strtolower($wanted['name'])) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingIndexes[] = $wanted;
                }
            }

            $parts = [];
            if ([] !== $missingColumns) {
                $parts['columns'] = $missingColumns;
            }
            if (!$hasPrimary) {
                $parts['primary'] = true;
            }
            if ([] !== $missingIndexes) {
                $parts['indexes'] = $missingIndexes;
            }

            return $parts;
        } catch (\Throwable) {
            return [];
        }
    }
}
