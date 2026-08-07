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

namespace Vtinnovations\ContaoMultilingualPagetree\Storage;

use Psr\Log\LoggerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageReader;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\SealedPackage;

/** Isolates every root/domain pair below a hash-only private directory. */
final class RootScopedPackageStore implements PackageStoreInterface
{
    /** @var array<string, FilesystemPackageStore> */
    private array $stores = [];

    public function __construct(
        private readonly string $baseDirectory,
        private readonly PackageReader $reader,
        private readonly CanonicalHost $domains,
        private readonly RootScope $context,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function load(int $now): ?SealedPackage { return $this->storeForScope()?->load($now); }
    public function exists(): bool { return $this->storeForScope()?->exists() ?? false; }
    public function verifiedHost(): ?string { return $this->storeForScope()?->verifiedHost(); }
    public function rememberVerifiedHost(string $host): void { $this->storeForScope()?->rememberVerifiedHost($host); }

    public function store(SealedPackage $package, string $verifiedHost, int $now): void
    {
        $store = $this->requireStore();
        $domain = $this->context->domain();
        if (null === $domain || !$this->domains->allMatch($domain, $verifiedHost, $package->document->boundHost)) {
            throw new PackageStoreException('The package does not match the selected root domain.');
        }
        $store->store($package, $domain, $now);
    }

    public function clear(): void { $this->requireStore()->clear(); }

    public function scopeDirectory(): ?string
    {
        if (!$this->context->isSelected()) {
            return null;
        }

        return rtrim($this->baseDirectory, '/').'/'.$this->context->rootId().'/'.hash('sha256', (string) $this->context->domain());
    }

    /** Cache discriminator without exposing document contents or paths. */
    public function stateFingerprint(): string
    {
        $directory = $this->scopeDirectory();
        if (null === $directory) {
            return 'unscoped';
        }
        $parts = [];
        foreach (['license.json', 'license.integrity.json', 'installation-host'] as $file) {
            $path = $directory.'/'.$file;
            $parts[] = is_link($path) || !is_file($path) ? 'missing' : ((string) @filemtime($path)).':'.((string) @filesize($path));
        }

        return hash('sha256', implode('|', $parts));
    }

    private function requireStore(): FilesystemPackageStore
    {
        $store = $this->storeForScope();
        if (null === $store) {
            throw new PackageStoreException('No root licence scope is selected.');
        }

        return $store;
    }

    private function storeForScope(): ?FilesystemPackageStore
    {
        $directory = $this->scopeDirectory();
        if (null === $directory) {
            return null;
        }
        $rootDirectory = rtrim($this->baseDirectory, '/').'/'.$this->context->rootId();
        if (is_link($this->baseDirectory) || is_link($rootDirectory) || is_link($directory)) {
            throw new PackageStoreException('Refusing a symbolic link in root-scoped licence storage.');
        }

        return $this->stores[$directory] ??= new FilesystemPackageStore($directory, $this->reader, $this->domains, $this->logger);
    }
}
