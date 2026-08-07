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

namespace Vtinnovations\ContaoMultilingualPagetree\Distribution;

use Psr\Log\LoggerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\SealedPackage;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreException;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RequestLedgerException;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RequestLedgerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;

/**
 * The one place where already verified state becomes active state.
 *
 * Both the outbound activation/refresh path and the inbound server-initiated
 * update path end here, so the host rule, the rollback rule and the atomic
 * write are applied identically no matter who offered the package.
 *
 * Cryptographic verification happens before this point; what this class adds is
 * installation context: the exact host, the version ordering and a cluster-wide
 * lock around the swap.
 */
final class PackageActivator
{
    /** Cluster lock name; short enough for MySQL `GET_LOCK`. */
    private const LOCK = 'cmp_state_activation';

    public function __construct(
        private readonly PackageStoreInterface $store,
        private readonly RequestLedgerInterface $ledger,
        private readonly CanonicalHost $hosts,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RootScope $rootContext = null,
    ) {
    }

    /**
     * Applies a verified package.
     *
     * @param string $trustedHost the host this installation actually answers on
     * @param string $packetHost  the host the packet claims to address
     */
    public function apply(SealedPackage $package, string $trustedHost, string $packetHost, int $now): ActivationOutcome
    {
        // Exact equality between the trusted installation host, the packet host
        // and the signed host. No apex, `www`, parent, child or sibling variant
        // is accepted, in either direction.
        if (!$this->hosts->allMatch($trustedHost, $packetHost, $package->document->boundHost)) {
            $this->logger?->warning('Contao Multilingual Pagetree: a package was refused because the host binding did not match exactly.');

            return ActivationOutcome::HostMismatch;
        }

        try {
            $lock = self::LOCK.'_'.substr(hash('sha256', $this->rootContext?->key() ?? 'legacy'), 0, 16);

            return $this->ledger->withLock($lock, ProductProfile::LOCK_TIMEOUT, fn (): ActivationOutcome => $this->swap($package, $trustedHost, $now));
        } catch (RequestLedgerException) {
            $this->logger?->warning('Contao Multilingual Pagetree: the activation lock was unavailable.');

            return ActivationOutcome::Busy;
        }
    }

    /** The active version, or null when nothing verifiable is stored. */
    public function activeVersion(int $now): ?int
    {
        return $this->store->load($now)?->document->version;
    }

    private function swap(SealedPackage $package, string $trustedHost, int $now): ActivationOutcome
    {
        $active = $this->store->load($now);

        if (null !== $active) {
            $outcome = $this->compare($active, $package);

            if (null !== $outcome) {
                return $outcome;
            }
        }

        try {
            $this->store->store($package, $trustedHost, $now);
        } catch (PackageStoreException) {
            $this->logger?->error('Contao Multilingual Pagetree: registration state could not be activated.');

            return ActivationOutcome::StorageFailure;
        }

        $this->logger?->info('Contao Multilingual Pagetree: registration state updated.', [
            'version' => $package->document->version,
        ]);

        return ActivationOutcome::Applied;
    }

    /**
     * Version ordering. Returns null when the offered package may be written.
     */
    private function compare(SealedPackage $active, SealedPackage $offered): ?ActivationOutcome
    {
        if ($offered->document->version < $active->document->version) {
            // Rollback protection: a correctly signed but older package must
            // never replace newer state, or a revocation could be undone by
            // replaying the package that preceded it.
            return ActivationOutcome::Older;
        }

        if ($offered->document->version === $active->document->version) {
            return hash_equals($active->fingerprint(), $offered->fingerprint())
                ? ActivationOutcome::AlreadyCurrent
                : ActivationOutcome::Conflict;
        }

        if ($offered->document->issuedAt !== $active->document->issuedAt
            || $offered->document->startsAt !== $active->document->startsAt
        ) {
            // Not an error - the issuer is authoritative - but a change of the
            // historical issue data is worth an operator-visible note.
            $this->logger?->info('Contao Multilingual Pagetree: the issuer changed the recorded issue or start date.');
        }

        return null;
    }
}
