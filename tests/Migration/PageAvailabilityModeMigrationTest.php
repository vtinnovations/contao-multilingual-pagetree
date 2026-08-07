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
use Vtinnovations\ContaoMultilingualPagetree\Migration\PageAvailabilityModeMigration;

class PageAvailabilityModeMigrationTest extends TestCase
{
    /** Requirement 6 */
    public function testExistingNonDefaultLanguagesBecomeFallback(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'pageAvailabilityMode' => ''],
            ['id' => 3, 'pageAvailabilityMode' => null],
        ]);

        $connection
            ->expects($this->exactly(2))
            ->method('update')
            ->with('tl_inline_language', ['pageAvailabilityMode' => 'fallback'], $this->anything())
        ;

        $migration = new PageAvailabilityModeMigration($connection);

        $this->assertTrue($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    /** Requirement 7 */
    public function testExistingValidValuesAreNotOverwritten(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'pageAvailabilityMode' => 'strict'],
            ['id' => 3, 'pageAvailabilityMode' => 'fallback'],
        ]);

        $connection->expects($this->never())->method('update');

        $migration = new PageAvailabilityModeMigration($connection);

        $this->assertFalse($migration->shouldRun());
    }

    public function testInvalidValuesAreNormalised(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'pageAvailabilityMode' => 'nonsense'],
        ]);

        $connection
            ->expects($this->once())
            ->method('update')
            ->with('tl_inline_language', ['pageAvailabilityMode' => 'fallback'], ['id' => 2])
        ;

        (new PageAvailabilityModeMigration($connection))->run();
    }

    /** Requirement 8 */
    public function testTheMigrationIsIdempotent(): void
    {
        // The state after a first run: every non-default record is normalised.
        $connection = $this->connection([
            ['id' => 2, 'pageAvailabilityMode' => 'fallback'],
            ['id' => 3, 'pageAvailabilityMode' => 'strict'],
        ]);

        $connection->expects($this->never())->method('update');

        $migration = new PageAvailabilityModeMigration($connection);

        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    public function testOnlyNonDefaultLanguagesAreSelected(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(['pageavailabilitymode' => new \stdClass()]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection
            ->expects($this->atLeastOnce())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('fallback != 1'))
            ->willReturn([])
        ;

        $this->assertFalse((new PageAvailabilityModeMigration($connection))->shouldRun());
    }

    public function testAMissingTableIsNotAnError(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertFalse((new PageAvailabilityModeMigration($connection))->shouldRun());
    }

    public function testAMissingColumnIsNotAnError(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(['language' => new \stdClass()]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertFalse((new PageAvailabilityModeMigration($connection))->shouldRun());
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
            'pageavailabilitymode' => new \stdClass(),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturn($records);

        return $connection;
    }
}
