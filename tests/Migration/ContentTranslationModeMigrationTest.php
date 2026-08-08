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
use Vtinnovations\ContaoMultilingualPagetree\Migration\ContentTranslationModeMigration;

class ContentTranslationModeMigrationTest extends TestCase
{
    /** Requirement 6 */
    public function testExistingLanguagesBecomeConnected(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'contentTranslationMode' => ''],
            ['id' => 3, 'contentTranslationMode' => null],
        ]);

        $connection
            ->expects($this->exactly(2))
            ->method('update')
            ->with('tl_inline_language', ['contentTranslationMode' => 'connected'], $this->anything())
        ;

        $migration = new ContentTranslationModeMigration($connection);

        $this->assertTrue($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    /** Requirement 7 */
    public function testConfiguredValuesArePreserved(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'contentTranslationMode' => 'free'],
            ['id' => 3, 'contentTranslationMode' => 'connected'],
        ]);

        $connection->expects($this->never())->method('update');

        $this->assertFalse((new ContentTranslationModeMigration($connection))->shouldRun());
    }

    public function testInvalidValuesAreNormalised(): void
    {
        $connection = $this->connection([['id' => 2, 'contentTranslationMode' => 'independent']]);

        $connection
            ->expects($this->once())
            ->method('update')
            ->with('tl_inline_language', ['contentTranslationMode' => 'connected'], ['id' => 2])
        ;

        (new ContentTranslationModeMigration($connection))->run();
    }

    /** Requirement 8 */
    public function testTheMigrationIsIdempotent(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'contentTranslationMode' => 'connected'],
            ['id' => 3, 'contentTranslationMode' => 'free'],
        ]);

        $connection->expects($this->never())->method('update');
        $migration = new ContentTranslationModeMigration($connection);

        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    /** Requirement 5: default-language records are not selected at all. */
    public function testOnlyNonDefaultLanguagesAreSelected(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(['contenttranslationmode' => new \stdClass()]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection
            ->expects($this->atLeastOnce())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('fallback != 1'))
            ->willReturn([])
        ;

        $this->assertFalse((new ContentTranslationModeMigration($connection))->shouldRun());
    }

    public function testAMissingColumnIsNotAnError(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(['language' => new \stdClass()]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertFalse((new ContentTranslationModeMigration($connection))->shouldRun());
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
            'contenttranslationmode' => new \stdClass(),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturn($records);

        return $connection;
    }
}
