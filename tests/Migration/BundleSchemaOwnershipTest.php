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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\EventListener\BundleSchemaListener;
use Vtinnovations\ContaoMultilingualPagetree\Schema\BundleSchema;
use Vtinnovations\ContaoMultilingualPagetree\Storage\DatabaseRequestLedger;

final class BundleSchemaOwnershipTest extends TestCase
{
    public function testLedgerDcaConsumesTheAuthoritativeColumnContract(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/contao/dca/tl_multilingual_pagetree_channel_ledger.php');

        self::assertStringContainsString("\$GLOBALS['TL_DCA']['tl_multilingual_pagetree_channel_ledger']", $source);
        self::assertStringContainsString('BundleSchema::LEDGER_COLUMNS', $source);
        self::assertStringContainsString("'request_id' => 'primary'", $source);
    }

    public function testLedgerContractMatchesTheLegacyMigrationDefinitionExactly(): void
    {
        self::assertSame([
            'request_id' => 'varchar(64) NOT NULL',
            'nonce_digest' => 'char(64) NOT NULL',
            'fingerprint' => 'char(64) NOT NULL',
            'result' => 'varchar(16) NOT NULL',
            'document_version' => 'int DEFAULT NULL',
            'claimed_at' => 'int NOT NULL',
            'completed_at' => 'int DEFAULT NULL',
        ], array_map(static fn (array $definition): string => $definition['sql'], BundleSchema::LEDGER_COLUMNS));
        self::assertSame(['request_id'], BundleSchema::LEDGER_PRIMARY_KEY);
        self::assertSame([
            ['table' => DatabaseRequestLedger::TABLE, 'name' => 'uniq_cmp_channel_nonce', 'columns' => ['nonce_digest'], 'unique' => true],
            ['table' => DatabaseRequestLedger::TABLE, 'name' => 'idx_cmp_channel_claimed', 'columns' => ['claimed_at'], 'unique' => false],
        ], BundleSchema::LEDGER_INDEXES);
    }

    public function testEveryIntegrityIndexHasStableOwnershipAndColumnOrder(): void
    {
        // 15 translation/ownership lookups plus the two language URL mapping
        // lookups the frontend resolves on every request of a configured site.
        self::assertCount(17, BundleSchema::INTEGRITY_INDEXES);
        self::assertCount(17, array_unique(array_map(
            static fn (array $index): string => $index['table'].'/'.$index['name'],
            BundleSchema::INTEGRITY_INDEXES,
        )));

        foreach (BundleSchema::INTEGRITY_INDEXES as $index) {
            self::assertStringStartsWith('tl_', $index['table']);
            self::assertStringStartsWith('clfmp_', $index['name']);
            self::assertNotSame([], $index['columns']);
            self::assertFalse($index['unique']);
        }
    }

    public function testSchemaListenerAddsNamedIndexesWithoutReplacingExistingKeys(): void
    {
        $schema = new Schema();
        foreach (BundleSchema::namedIndexes() as $definition) {
            if (!$schema->hasTable($definition['table'])) {
                $schema->createTable($definition['table']);
            }
            $table = $schema->getTable($definition['table']);
            foreach ($definition['columns'] as $column) {
                if (!$table->hasColumn($column)) {
                    $table->addColumn($column, 'string', ['length' => 64]);
                }
            }
        }

        $article = $schema->getTable('tl_article');
        $article->addIndex(['cmpLanguage'], 'pre_existing_key');

        $listener = new BundleSchemaListener();
        $listener->augmentSchema($schema);
        $listener->augmentSchema($schema);

        self::assertTrue($article->hasIndex('pre_existing_key'));
        foreach (BundleSchema::namedIndexes() as $definition) {
            $index = $schema->getTable($definition['table'])->getIndex($definition['name']);
            self::assertSame($definition['columns'], $index->getColumns());
            self::assertSame($definition['unique'], $index->isUnique());
        }
    }

    public function testRepairMigrationsNeverDropTablesOrIndexes(): void
    {
        $root = dirname(__DIR__, 2).'/src/Migration/';
        $source = (string) file_get_contents($root.'ChannelLedgerMigration.php')
            .(string) file_get_contents($root.'IntegrityIndexMigration.php');

        self::assertStringNotContainsString('DROP TABLE', strtoupper($source));
        self::assertStringNotContainsString('DROP INDEX', strtoupper($source));
        self::assertStringContainsString('BundleSchema::LEDGER_INDEXES', $source);
        self::assertStringContainsString('BundleSchema::INTEGRITY_INDEXES', $source);
        self::assertStringNotContainsString('tl_rea_', $source);
    }
}
