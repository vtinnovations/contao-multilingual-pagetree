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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Storage\FilesystemPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreException;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

/**
 * The stored pair is written atomically, re-verified on every read and never
 * repaired behind the operator's back.
 */
final class FilesystemPackageStoreTest extends TestCase
{
    private const NOW = PackageFactory::NOW;

    private string $directory;
    private PackageFactory $factory;
    private FilesystemPackageStore $store;

    protected function setUp(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('The sodium extension is not available.');
        }

        $this->directory = sys_get_temp_dir().'/cmp-state-'.bin2hex(random_bytes(6));
        $this->factory = new PackageFactory();
        $this->store = new FilesystemPackageStore($this->directory, $this->factory->reader(), new CanonicalHost());
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach ((array) glob($this->directory.'/{,.}*', GLOB_BRACE) as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);
    }

    public function testAPackageSurvivesAStoreAndLoadRoundTrip(): void
    {
        $package = $this->factory->sealedPackage();

        $this->store->store($package, PackageFactory::HOST, self::NOW);
        $loaded = $this->store->load(self::NOW);

        self::assertNotNull($loaded);
        self::assertSame($package->bytes, $loaded->bytes, 'The exact bytes must round-trip unchanged.');
        self::assertSame(PackageFactory::HOST, $this->store->verifiedHost());
        self::assertTrue($this->store->exists());
    }

    public function testTheStateIsWrittenOutsideThePublicTreeWithRestrictivePermissions(): void
    {
        $this->store->store($this->factory->sealedPackage(), PackageFactory::HOST, self::NOW);

        self::assertFileExists($this->directory.'/license.json');
        self::assertFileExists($this->directory.'/license.integrity.json');

        if ('\\' === DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX permissions are not meaningful here.');
        }

        self::assertSame('0640', substr(sprintf('%o', fileperms($this->directory.'/license.json')), -4));
        self::assertSame('0750', substr(sprintf('%o', fileperms($this->directory)), -4));
    }

    public function testEditingTheStoredDocumentIsDetectedOnTheNextRead(): void
    {
        $this->store->store($this->factory->sealedPackage(), PackageFactory::HOST, self::NOW);

        $path = $this->directory.'/license.json';
        file_put_contents($path, str_replace('"license_package":"free"', '"license_package":"free" ', (string) file_get_contents($path)));

        self::assertNull($this->store->load(self::NOW), 'Tampered state must read as unusable.');
        self::assertTrue($this->store->exists(), 'It is reported, not silently deleted.');
    }

    public function testEditingTheSealIsDetectedOnTheNextRead(): void
    {
        $this->store->store($this->factory->sealedPackage(), PackageFactory::HOST, self::NOW);

        $path = $this->directory.'/license.integrity.json';
        $seal = json_decode((string) file_get_contents($path), true);
        $seal['license_version'] = 99;
        file_put_contents($path, json_encode($seal, JSON_THROW_ON_ERROR));

        self::assertNull($this->store->load(self::NOW));
    }

    public function testANewerPackageReplacesTheStoredOneAtomically(): void
    {
        $this->store->store($this->factory->sealedPackage(), PackageFactory::HOST, self::NOW);
        $this->store->store($this->factory->sealedPackage(['license_version' => 9]), PackageFactory::HOST, self::NOW);

        self::assertSame(9, $this->store->load(self::NOW)?->document->version);
        self::assertSame([], glob($this->directory.'/*.tmp'), 'No temporary file may survive an activation.');
        self::assertSame([], glob($this->directory.'/*.bak'), 'No backup may survive a successful activation.');
    }

    public function testAStoreWithoutACanonicalHostIsRefused(): void
    {
        $this->expectException(PackageStoreException::class);

        $this->store->store($this->factory->sealedPackage(), 'not a host', self::NOW);
    }

    public function testTheHostRecordFollowsTheLastProvenHost(): void
    {
        $this->store->store($this->factory->sealedPackage(), PackageFactory::HOST, self::NOW);

        $this->store->rememberVerifiedHost('www.example.com');

        self::assertSame('www.example.com', $this->store->verifiedHost(), 'The record follows the last proven host.');
    }

    public function testClearRemovesEverythingItOwns(): void
    {
        $this->store->store($this->factory->sealedPackage(), PackageFactory::HOST, self::NOW);
        $this->store->clear();

        self::assertFalse($this->store->exists());
        self::assertNull($this->store->load(self::NOW));
        self::assertNull($this->store->verifiedHost());
    }

    public function testAMissingDirectoryIsSimplyAnUnactivatedInstallation(): void
    {
        $store = new FilesystemPackageStore($this->directory.'/never-created', $this->factory->reader(), new CanonicalHost());

        self::assertFalse($store->exists());
        self::assertNull($store->load(self::NOW));
        self::assertNull($store->verifiedHost());
    }
}
