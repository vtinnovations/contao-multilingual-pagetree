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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\Packaging\SealedPackage;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreException;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;

/**
 * A package store that keeps everything in memory, so tests can assert what was
 * written without touching the filesystem.
 */
final class InMemoryPackageStore implements PackageStoreInterface
{
    public ?SealedPackage $package = null;
    public ?string $host = null;
    public bool $failWrites = false;
    public bool $unreadable = false;
    public int $writes = 0;

    public function load(int $now): ?SealedPackage
    {
        return $this->unreadable ? null : $this->package;
    }

    public function store(SealedPackage $package, string $verifiedHost, int $now): void
    {
        if ($this->failWrites) {
            throw new PackageStoreException('storage refused');
        }

        $this->package = $package;
        $this->host = $verifiedHost;
        ++$this->writes;
    }

    public function verifiedHost(): ?string
    {
        return $this->host;
    }

    public function rememberVerifiedHost(string $host): void
    {
        $this->host ??= $host;
    }

    public function clear(): void
    {
        $this->package = null;
        $this->host = null;
    }

    public function exists(): bool
    {
        return null !== $this->package || $this->unreadable;
    }
}
