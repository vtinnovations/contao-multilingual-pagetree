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

use Vtinnovations\ContaoMultilingualPagetree\Packaging\SealedPackage;

/**
 * Persistence port for the stored pair: the exact document bytes and the signed
 * seal that vouches for them.
 *
 * Implementations must activate both parts as one logical transaction. State in
 * which the bytes and the seal disagree must never be observable.
 */
interface PackageStoreInterface
{
    /**
     * The stored, fully re-verified package, or null when nothing usable is
     * stored. A verification failure is reported, never repaired.
     */
    public function load(int $now): ?SealedPackage;

    /**
     * Atomically replaces the stored pair and records the installation host that
     * was proven at the moment of writing.
     *
     * @throws PackageStoreException when the new state could not be activated;
     *                               the previous state then remains active
     */
    public function store(SealedPackage $package, string $verifiedHost, int $now): void;

    /**
     * The installation host proven during the last activation, refresh or push.
     *
     * Non-HTTP execution (console commands, queues, schedulers) validates
     * against this recorded identity instead of deriving a host from the
     * environment.
     */
    public function verifiedHost(): ?string;

    /**
     * Records a host that has just been proven equal to the bound host during a
     * trusted HTTP request. Used only to seed the record for installations that
     * were activated from the command line.
     */
    public function rememberVerifiedHost(string $host): void;

    /** Removes the stored pair. Used by the explicit deactivation path only. */
    public function clear(): void;

    /** Whether any state exists at all, without verifying it. */
    public function exists(): bool;
}
