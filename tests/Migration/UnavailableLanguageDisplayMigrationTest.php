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
use Vtinnovations\ContaoMultilingualPagetree\Migration\UnavailableLanguageDisplayMigration;

class UnavailableLanguageDisplayMigrationTest extends TestCase
{
    /** Requirement 40: existing module records migrate safely. */
    public function testExistingModulesBecomeHide(): void
    {
        $connection = $this->connection([
            ['id' => 4, 'unavailableLanguageDisplay' => ''],
            ['id' => 5, 'unavailableLanguageDisplay' => null],
        ]);

        $connection
            ->expects($this->exactly(2))
            ->method('update')
            ->with('tl_module', ['unavailableLanguageDisplay' => 'hide'], $this->anything())
        ;

        $migration = new UnavailableLanguageDisplayMigration($connection);

        $this->assertTrue($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    public function testConfiguredValuesAreNotOverwritten(): void
    {
        $connection = $this->connection([
            ['id' => 4, 'unavailableLanguageDisplay' => 'disabled'],
            ['id' => 5, 'unavailableLanguageDisplay' => 'hide'],
        ]);

        $connection->expects($this->never())->method('update');

        $this->assertFalse((new UnavailableLanguageDisplayMigration($connection))->shouldRun());
    }

    public function testInvalidValuesAreNormalised(): void
    {
        $connection = $this->connection([
            ['id' => 4, 'unavailableLanguageDisplay' => 'redirect'],
        ]);

        $connection
            ->expects($this->once())
            ->method('update')
            ->with('tl_module', ['unavailableLanguageDisplay' => 'hide'], ['id' => 4])
        ;

        (new UnavailableLanguageDisplayMigration($connection))->run();
    }

    public function testTheMigrationIsIdempotent(): void
    {
        $connection = $this->connection([
            ['id' => 4, 'unavailableLanguageDisplay' => 'hide'],
            ['id' => 5, 'unavailableLanguageDisplay' => 'disabled'],
        ]);

        $connection->expects($this->never())->method('update');

        $migration = new UnavailableLanguageDisplayMigration($connection);

        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    public function testOnlyLanguageSwitcherModulesAreSelected(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(['unavailablelanguagedisplay' => new \stdClass()]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection
            ->expects($this->atLeastOnce())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('type = :type'), ['type' => 'language_switcher'])
            ->willReturn([])
        ;

        $this->assertFalse((new UnavailableLanguageDisplayMigration($connection))->shouldRun());
    }

    public function testAMissingColumnIsNotAnError(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(['type' => new \stdClass()]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertFalse((new UnavailableLanguageDisplayMigration($connection))->shouldRun());
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
            'unavailablelanguagedisplay' => new \stdClass(),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturn($records);

        return $connection;
    }
}
