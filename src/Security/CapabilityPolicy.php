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

namespace Vtinnovations\ContaoMultilingualPagetree\Security;

use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Helper\Clock;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\InstallationIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\DocumentStatus;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\SealedPackage;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RootScopedPackageStore;

/**
 * Evaluates the stored state against this installation and this moment.
 *
 * The evaluation happens once per request and is then reused, because it reads
 * files and re-verifies signatures. The result is immutable; consumers ask it
 * about one capability at a time at their own boundary.
 *
 * The host rule is applied here as well as during activation: state copied to a
 * different domain or subdomain fails before any capability is granted, even
 * though the copied files verify cryptographically.
 */
final class CapabilityPolicy
{
    /** @var array<string, CapabilityDecision> */
    private array $decisions = [];

    public function __construct(
        private readonly PackageStoreInterface $store,
        private readonly InstallationIdentity $identity,
        private readonly CanonicalHost $hosts,
        private readonly Clock $clock,
        private readonly ?RootScope $rootContext = null,
    ) {
    }

    /** The current decision, evaluated at most once per request. */
    public function decision(): CapabilityDecision
    {
        $key = $this->rootContext?->key() ?? 'legacy';
        if ($this->store instanceof RootScopedPackageStore) {
            $key .= '|'.$this->store->stateFingerprint();
        }

        return $this->decisions[$key] ??= $this->evaluate();
    }

    /** The one question a protected operation asks at its own boundary. */
    public function allows(Capability $capability): bool
    {
        return $this->decision()->allows($capability);
    }

    /** Drops the memoised decision; called between worker cycles. */
    public function reset(): void
    {
        $this->decisions = [];
    }

    private function evaluate(): CapabilityDecision
    {
        $now = $this->clock->now();
        try {
            $package = $this->store->load($now);
            $exists = $this->store->exists();
        } catch (\Throwable) {
            return CapabilityDecision::denied(CapabilityDenial::StateUnusable);
        }

        if (null === $package) {
            return CapabilityDecision::denied(
                $exists ? CapabilityDenial::StateUnusable : CapabilityDenial::NotActivated,
            );
        }

        $document = $package->document;
        $host = $this->identity->current();

        if (null === $host) {
            return CapabilityDecision::denied(CapabilityDenial::HostUnknown, $document->boundHost, $document->version);
        }

        // Exact-host comparison, constant time, never a suffix or substring
        // match: `example.com` state does not run on `www.example.com`, on any
        // subdomain, on a sibling or on a parent.
        if (!$this->hosts->matches($document->boundHost, $host)) {
            return CapabilityDecision::denied(CapabilityDenial::HostMismatch, $document->boundHost, $document->version);
        }

        // Authentic state from before the signed host set existed. It is kept
        // exactly as it is - it is still the rollback material for this scope -
        // but it cannot authorise anything until a refresh has fetched the set,
        // because inventing that set locally is precisely what must not happen.
        if ($document->isLegacyHostBinding()) {
            return CapabilityDecision::denied(CapabilityDenial::RefreshRequired, $document->boundHost, $document->version);
        }

        // Membership in the signed set, in addition to equality with the signed
        // operation host: two independent reasons this host is authorised, so a
        // change to either one alone cannot silently widen the binding.
        if (!$document->authorises($host, $this->hosts)) {
            return CapabilityDecision::denied(CapabilityDenial::HostMismatch, $document->boundHost, $document->version);
        }

        if ($this->identity->isRequestBound()) {
            // Seeds the record used by console commands and workers, which have
            // no trustworthy host of their own.
            $this->store->rememberVerifiedHost($host);
        }

        return $this->evaluatePeriod($package, $now);
    }

    private function evaluatePeriod(SealedPackage $package, int $now): CapabilityDecision
    {
        $document = $package->document;

        if (DocumentStatus::Valid !== $document->status) {
            // Revoked, suspended, invalid and expired all grant nothing. There
            // is no tier below this one to fall back to, so `free_available`
            // cannot authorise anything here - the product is already free, and
            // treating it as a fallback switch would turn a withdrawn
            // entitlement back on.
            return CapabilityDecision::denied(CapabilityDenial::StatusNotValid, $document->boundHost, $document->version);
        }

        // The product is issued for life. A document carrying an end date is
        // structurally valid and may well be authentic, but it is not the
        // entitlement this product is sold under, so it is refused rather than
        // honoured until its expiry. This is checked before the start date so an
        // incompatible term is reported as such instead of as "not yet valid".
        if (!$document->lifetime) {
            return CapabilityDecision::denied(CapabilityDenial::TermNotSupported, $document->boundHost, $document->version);
        }

        if ($now < $document->startsAt) {
            return CapabilityDecision::denied(CapabilityDenial::NotYetValid, $document->boundHost, $document->version);
        }

        return CapabilityDecision::granted(
            $document->tier,
            $this->capabilities($document->grantedFeatures()),
            $document->boundHost,
            $document->version,
            $document->expiresAt,
            $document->lifetime,
        );
    }

    /**
     * @param list<string> $identifiers
     *
     * @return list<Capability>
     */
    private function capabilities(array $identifiers): array
    {
        $capabilities = [];

        foreach ($identifiers as $identifier) {
            $capability = Capability::tryFromValue($identifier);

            // An identifier this release does not know is ignored rather than
            // guessed: a newer feature name must not unlock an older gate.
            if (null !== $capability && !in_array($capability, $capabilities, true)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }
}
