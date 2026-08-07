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
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Migration\LanguageUrlMigration;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageDomainNormalizer;

class LanguageUrlMigrationTest extends TestCase
{
    /** Existing rows keep their behaviour: empty stays empty. */
    public function testEmptyValuesAreLeftUntouched(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'urlProtocol' => '', 'urlDomain' => '', 'urlEntryPoint' => ''],
            ['id' => 3, 'urlProtocol' => '', 'urlDomain' => '', 'urlEntryPoint' => ''],
        ]);

        $connection->expects($this->never())->method('update');

        $this->assertFalse($this->migration($connection)->shouldRun());
    }

    public function testCanonicalValuesAreNotRewritten(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'urlProtocol' => 'https', 'urlDomain' => 'www.xyz.de', 'urlEntryPoint' => '/de'],
            ['id' => 3, 'urlProtocol' => 'http', 'urlDomain' => '', 'urlEntryPoint' => '/'],
        ]);

        $connection->expects($this->never())->method('update');

        $this->assertFalse($this->migration($connection)->shouldRun());
    }

    public function testNonCanonicalValuesAreNormalised(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'urlProtocol' => 'HTTPS', 'urlDomain' => 'WWW.XYZ.DE.', 'urlEntryPoint' => 'de/'],
        ]);

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'tl_inline_language',
                ['urlProtocol' => 'https', 'urlDomain' => 'www.xyz.de', 'urlEntryPoint' => '/de'],
                ['id' => 2],
            )
        ;

        $this->assertTrue($this->migration($connection)->run()->isSuccessful());
    }

    /** An unusable value is cleared, which returns the row to its old behaviour. */
    public function testUnusableValuesAreClearedRatherThanGuessed(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'urlProtocol' => 'ftp', 'urlDomain' => 'https://www.xyz.de/de', 'urlEntryPoint' => '/de/../admin'],
        ]);

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'tl_inline_language',
                ['urlProtocol' => '', 'urlDomain' => '', 'urlEntryPoint' => ''],
                ['id' => 2],
            )
        ;

        $this->migration($connection)->run();
    }

    public function testTheMigrationIsIdempotent(): void
    {
        $connection = $this->connection([
            ['id' => 2, 'urlProtocol' => 'https', 'urlDomain' => 'www.xyz.de', 'urlEntryPoint' => '/de'],
        ]);

        $connection->expects($this->never())->method('update');

        $migration = $this->migration($connection);

        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
    }

    public function testAMissingTableIsNotAnError(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertFalse($this->migration($connection)->shouldRun());
    }

    public function testAMissingColumnIsNotAnError(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(['language' => new \stdClass()]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertFalse($this->migration($connection)->shouldRun());
    }

    private function migration(Connection $connection): LanguageUrlMigration
    {
        return new LanguageUrlMigration(
            $connection,
            new LanguageDomainNormalizer(new CanonicalHost()),
            new EntryPointNormalizer(),
        );
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
            'urlprotocol' => new \stdClass(),
            'urldomain' => new \stdClass(),
            'urlentrypoint' => new \stdClass(),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturn($records);

        return $connection;
    }
}
