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
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageFormatException;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageReader;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\SealedPackage;

/**
 * Stores the pair in a private directory below the project's `var/` working
 * area - never below the document root, never inside the bundle's own source,
 * and never at a path that any request can influence. The path is a container
 * parameter, so no request value can ever reach it.
 *
 * Activation is a small transaction:
 *
 *  1. take an exclusive lock on a dedicated lock file;
 *  2. write both files to temporary files on the same filesystem and fsync them;
 *  3. re-read and re-verify the temporary pair;
 *  4. back up the currently active pair;
 *  5. rename both temporary files into place;
 *  6. re-read and re-verify the now active pair;
 *  7. roll the backup back in if anything in step 6 fails;
 *  8. clean up and release the lock.
 *
 * The two renames cannot be a single POSIX operation, which is why step 6 and a
 * rollback path exist: a half-applied pair is detected immediately and reverted
 * rather than left behind.
 */
final class FilesystemPackageStore implements PackageStoreInterface
{
    private const DOCUMENT_FILE = 'license.json';
    private const SEAL_FILE = 'license.integrity.json';
    private const HOST_FILE = 'installation-host';
    private const LOCK_FILE = '.state.lock';
    private const DIRECTORY_MODE = 0750;
    private const FILE_MODE = 0640;

    public function __construct(
        private readonly string $directory,
        private readonly PackageReader $reader,
        private readonly CanonicalHost $hosts,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function load(int $now): ?SealedPackage
    {
        $bytes = $this->read($this->path(self::DOCUMENT_FILE));
        $seal = $this->read($this->path(self::SEAL_FILE));

        if (null === $bytes || null === $seal) {
            return null;
        }

        try {
            return $this->reader->readStored($bytes, $seal, $now);
        } catch (PackageFormatException $exception) {
            // State that no longer verifies is reported, never repaired:
            // repairing it would be exactly the tampering path we defend
            // against.
            $this->logger?->warning('Contao Multilingual Pagetree: stored registration state failed verification.', [
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function exists(): bool
    {
        $document = $this->path(self::DOCUMENT_FILE);
        $seal = $this->path(self::SEAL_FILE);

        return !is_link($document) && !is_link($seal) && is_file($document) && is_file($seal);
    }

    public function verifiedHost(): ?string
    {
        $host = $this->read($this->path(self::HOST_FILE));

        return null === $host ? null : $this->hosts->normalize(trim($host));
    }

    public function rememberVerifiedHost(string $host): void
    {
        $canonical = $this->hosts->normalize($host);

        if (null === $canonical || $canonical === $this->verifiedHost()) {
            return;
        }

        try {
            $this->ensureDirectory();
            $this->writeFile($this->path(self::HOST_FILE), $canonical."\n");
        } catch (PackageStoreException $exception) {
            // The record is an optimisation for non-HTTP execution; failing to
            // write it must not break the request that noticed it was missing.
            $this->logger?->warning('Contao Multilingual Pagetree: the installation host record could not be written.', [
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    public function store(SealedPackage $package, string $verifiedHost, int $now): void
    {
        $host = $this->hosts->normalize($verifiedHost);

        if (null === $host) {
            throw new PackageStoreException('Refusing to store state without a canonical installation host.');
        }

        $this->ensureDirectory();

        $lock = $this->acquireLock();

        try {
            $documentPath = $this->path(self::DOCUMENT_FILE);
            $sealPath = $this->path(self::SEAL_FILE);
            $documentTemp = $documentPath.'.'.bin2hex(random_bytes(8)).'.tmp';
            $sealTemp = $sealPath.'.'.bin2hex(random_bytes(8)).'.tmp';
            $documentBackup = $documentPath.'.bak';
            $sealBackup = $sealPath.'.bak';

            try {
                $this->writeFile($documentTemp, $package->bytes);
                $this->writeFile($sealTemp, $package->sealJson());

                // The temporary pair must verify before anything is activated.
                $this->reader->readStored(
                    (string) $this->read($documentTemp),
                    (string) $this->read($sealTemp),
                    $now,
                );

                $hadPrevious = $this->backup($documentPath, $documentBackup) && $this->backup($sealPath, $sealBackup);

                if (!@rename($documentTemp, $documentPath) || !@rename($sealTemp, $sealPath)) {
                    $this->rollback($hadPrevious, $documentPath, $sealPath, $documentBackup, $sealBackup);

                    throw new PackageStoreException('The state pair could not be activated.');
                }

                try {
                    $active = $this->load($now);

                    if (null === $active || !hash_equals($package->bytes, $active->bytes)) {
                        throw new PackageStoreException('The activated state pair did not verify.');
                    }
                } catch (PackageStoreException $exception) {
                    $this->rollback($hadPrevious, $documentPath, $sealPath, $documentBackup, $sealBackup);

                    throw $exception;
                }

                // Only now, with a verified active pair, is the proven host
                // recorded for non-HTTP execution.
                $this->writeFile($this->path(self::HOST_FILE), $host."\n");

                $this->remove($documentBackup);
                $this->remove($sealBackup);
            } catch (PackageFormatException $exception) {
                throw new PackageStoreException('The state pair failed post-write verification: '.$exception->getMessage(), 0, $exception);
            } finally {
                $this->remove($documentTemp);
                $this->remove($sealTemp);
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function clear(): void
    {
        $lock = $this->acquireLock();

        try {
            foreach ([self::DOCUMENT_FILE, self::SEAL_FILE, self::HOST_FILE] as $file) {
                $this->remove($this->path($file));
                $this->remove($this->path($file).'.bak');
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** The private directory this store owns; used by diagnostics only. */
    public function directory(): string
    {
        return $this->directory;
    }

    private function path(string $file): string
    {
        return rtrim($this->directory, '/').'/'.$file;
    }

    private function read(string $path): ?string
    {
        if (is_link($path)) {
            $this->logger?->warning('Contao Multilingual Pagetree: refused a symbolic link in licence storage.');

            return null;
        }
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (is_link($path)) {
            throw new PackageStoreException('Refusing to write licence state through a symbolic link.');
        }
        $handle = @fopen($path, 'wb');

        if (false === $handle) {
            throw new PackageStoreException('The state directory is not writable.');
        }

        try {
            if (false === @fwrite($handle, $contents)) {
                throw new PackageStoreException('The state could not be written.');
            }

            @fflush($handle);

            // Best-effort durability: on a crash between rename and flush the
            // pair could otherwise reappear mismatched.
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            @fclose($handle);
        }

        @chmod($path, self::FILE_MODE);
    }

    private function backup(string $path, string $backup): bool
    {
        if (is_link($path) || is_link($backup)) {
            throw new PackageStoreException('Refusing a symbolic link in licence backup state.');
        }
        if (!is_file($path)) {
            return false;
        }

        return @copy($path, $backup);
    }

    private function rollback(bool $hadPrevious, string $documentPath, string $sealPath, string $documentBackup, string $sealBackup): void
    {
        if (!$hadPrevious) {
            // Nothing was active before, so removing the half-applied pair is
            // the correct rollback: the installation returns to "not activated".
            $this->remove($documentPath);
            $this->remove($sealPath);

            return;
        }

        if (!@rename($documentBackup, $documentPath) || !@rename($sealBackup, $sealPath)) {
            $this->logger?->critical('Contao Multilingual Pagetree: the previous registration state could not be restored.');
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path)) {
            throw new PackageStoreException('Refusing a symbolic link in licence storage.');
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function ensureDirectory(): void
    {
        if (is_link($this->directory)) {
            throw new PackageStoreException('Refusing a symbolic link as the licence state directory.');
        }
        if (is_dir($this->directory)) {
            return;
        }

        if (!@mkdir($this->directory, self::DIRECTORY_MODE, true) && !is_dir($this->directory)) {
            throw new PackageStoreException('The state directory could not be created.');
        }
    }

    /**
     * @return resource
     */
    private function acquireLock()
    {
        $this->ensureDirectory();

        $lockPath = $this->path(self::LOCK_FILE);
        if (is_link($lockPath)) {
            throw new PackageStoreException('Refusing a symbolic link as the licence state lock.');
        }
        $handle = @fopen($lockPath, 'cb');

        if (false === $handle) {
            throw new PackageStoreException('The state lock could not be opened.');
        }

        if (!flock($handle, LOCK_EX)) {
            @fclose($handle);

            throw new PackageStoreException('The state lock could not be acquired.');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
