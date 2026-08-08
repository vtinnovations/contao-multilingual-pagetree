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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RootScopedPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreException;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

final class RootScopeTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    public function testRootsAndExactSubdomainsProduceDifferentStores(): void
    {
        if (!PackageFactory::isSupported()) self::markTestSkipped('Sodium is required.');
        $context = new RootScope();
        $store = new RootScopedPackageStore(sys_get_temp_dir().'/cmp-unused-licences', (new PackageFactory())->reader(), new CanonicalHost(), $context);
        $context->select(1, 'example.com');
        $first = $store->scopeDirectory();
        $context->select(2, 'example.com');
        $second = $store->scopeDirectory();
        $context->select(1, 'www.example.com');
        $www = $store->scopeDirectory();
        $context->select(1, 'shop.example.com');
        $shop = $store->scopeDirectory();

        self::assertNotSame($first, $second);
        self::assertNotSame($first, $www);
        self::assertNotSame($first, $shop);
        self::assertStringNotContainsString('example.com', (string) $first);
    }

    public function testResetRemovesTheSelectedRoot(): void
    {
        $context = new RootScope();
        $context->select(10, 'example.com');
        $context->reset();
        self::assertFalse($context->isSelected());
    }

    public function testStoredLicenceCannotLeakToAnotherRootOrSubdomain(): void
    {
        if (!PackageFactory::isSupported()) self::markTestSkipped('Sodium is required.');
        $directory = sys_get_temp_dir().'/cmp-root-store-'.bin2hex(random_bytes(6));
        $this->directories[] = $directory;
        $factory = new PackageFactory();
        $context = new RootScope();
        $store = new RootScopedPackageStore($directory, $factory->reader(), new CanonicalHost(), $context);
        $context->select(1, 'example.com');
        $store->store($factory->sealedPackage(), 'example.com', PackageFactory::NOW);
        self::assertNotNull($store->load(PackageFactory::NOW));

        $context->select(2, 'example.com');
        self::assertFalse($store->exists());
        self::assertNull($store->load(PackageFactory::NOW));

        $context->select(1, 'www.example.com');
        self::assertFalse($store->exists());
        $context->select(1, 'shop.example.com');
        self::assertFalse($store->exists());
        $this->expectException(PackageStoreException::class);
        $store->store($factory->sealedPackage(), 'shop.example.com', PackageFactory::NOW);
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) $this->removeTree($directory);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $path = $directory.'/'.$name;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
