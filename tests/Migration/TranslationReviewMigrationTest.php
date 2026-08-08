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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Migration\TranslationReviewMigration;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

class TranslationReviewMigrationTest extends TestCase
{
    /** Requirement 79 */
    public function testExistingRecordsBecomeUnreviewed(): void
    {
        $connection = $this->connection([
            ['id' => 1, 'reviewStatus' => '', 'reviewedSourceRevision' => ''],
            ['id' => 2, 'reviewStatus' => null, 'reviewedSourceRevision' => null],
        ]);

        $connection
            ->expects($this->atLeastOnce())
            ->method('update')
            ->with($this->anything(), [TranslationReviewResolver::FIELD_STATUS => ReviewStatus::Unreviewed->value], $this->anything())
        ;

        $migration = new TranslationReviewMigration($connection, new TranslationFieldRegistry());

        $this->assertTrue($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    /** Requirements 80, 81 and 82: only the status column is ever written. */
    public function testOnlyTheStatusColumnIsWritten(): void
    {
        $connection = $this->connection([
            ['id' => 1, 'reviewStatus' => 'nonsense', 'reviewedSourceRevision' => ''],
        ]);

        $connection
            ->expects($this->atLeastOnce())
            ->method('update')
            ->willReturnCallback(function (string $table, array $data, array $criteria): int {
                $this->assertSame([TranslationReviewResolver::FIELD_STATUS], array_keys($data));
                $this->assertSame(['id'], array_keys($criteria));

                return 1;
            })
        ;

        (new TranslationReviewMigration($connection, new TranslationFieldRegistry()))->run();
    }

    /** Requirement 83 */
    public function testValidExistingReviewMetadataIsPreserved(): void
    {
        $connection = $this->connection([
            ['id' => 1, 'reviewStatus' => 'up_to_date', 'reviewedSourceRevision' => str_repeat('a', 64)],
            ['id' => 2, 'reviewStatus' => 'needs_review', 'reviewedSourceRevision' => str_repeat('b', 64)],
            ['id' => 3, 'reviewStatus' => 'unreviewed', 'reviewedSourceRevision' => ''],
        ]);

        $connection->expects($this->never())->method('update');

        $this->assertFalse((new TranslationReviewMigration($connection, new TranslationFieldRegistry()))->shouldRun());
    }

    /**
     * A status that claims a review without a usable baseline is impossible and
     * is normalised, which also covers restored versions.
     */
    public function testAStatusWithoutAUsableBaselineIsNormalised(): void
    {
        $connection = $this->connection([
            ['id' => 1, 'reviewStatus' => 'up_to_date', 'reviewedSourceRevision' => ''],
        ]);

        $connection->expects($this->atLeastOnce())->method('update');

        $this->assertTrue((new TranslationReviewMigration($connection, new TranslationFieldRegistry()))->shouldRun());
    }

    /** Requirement 84 */
    public function testTheMigrationIsIdempotent(): void
    {
        $connection = $this->connection([
            ['id' => 1, 'reviewStatus' => 'unreviewed', 'reviewedSourceRevision' => ''],
            ['id' => 2, 'reviewStatus' => 'up_to_date', 'reviewedSourceRevision' => str_repeat('c', 64)],
        ]);

        $connection->expects($this->never())->method('update');
        $migration = new TranslationReviewMigration($connection, new TranslationFieldRegistry());

        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    /** Requirement 85: an orphaned record migrates like any other. */
    public function testOrphanedRecordsMigrateSafely(): void
    {
        $connection = $this->connection([
            ['id' => 1, 'reviewStatus' => 'needs_review', 'reviewedSourceRevision' => 'broken'],
        ]);

        $connection->expects($this->atLeastOnce())->method('update');

        $this->assertTrue((new TranslationReviewMigration($connection, new TranslationFieldRegistry()))->shouldRun());
    }

    public function testAMissingTableIsNotAnError(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertFalse((new TranslationReviewMigration($connection, new TranslationFieldRegistry()))->shouldRun());
    }

    public function testAMissingColumnIsNotAnError(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(['id' => new \stdClass()]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertFalse((new TranslationReviewMigration($connection, new TranslationFieldRegistry()))->shouldRun());
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connection(array $records): Connection
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn([
            'id' => new \stdClass(),
            strtolower(TranslationReviewResolver::FIELD_STATUS) => new \stdClass(),
            strtolower(TranslationReviewResolver::FIELD_REVISION) => new \stdClass(),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturn($records);

        return $connection;
    }
}
