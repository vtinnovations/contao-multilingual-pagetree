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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\ServiceTier;
use Vtinnovations\ContaoMultilingualPagetree\Security\Capability;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityDenial;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FrozenClock;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

/**
 * The product is issued under one model: a lifetime entitlement, free of charge,
 * granting every feature.
 *
 * "Free" is a price. It is not licence-free operation, and it is not a reduced
 * feature set. These tests pin both halves of that, because either one drifting
 * would be invisible in ordinary use: a product that quietly stopped requiring
 * activation would look fine, and so would one that quietly withheld a feature.
 */
final class LicenceModelTest extends TestCase
{
    private const NOW = PackageFactory::NOW;

    private PackageFactory $factory;

    protected function setUp(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('The sodium extension is not available.');
        }

        $this->factory = new PackageFactory();
    }

    public function testTheProductDeclaresTheLifetimeFreeModel(): void
    {
        self::assertSame('lifetime_free', ProductProfile::LICENCE_MODEL);
    }

    /** There is exactly one accepted package value, and it is the free one. */
    public function testExactlyOnePackageIsAccepted(): void
    {
        self::assertSame([ServiceTier::Free], ServiceTier::cases());
        self::assertSame('free', ServiceTier::Free->value);
    }

    /** No feature is held back behind a tier this product does not sell. */
    public function testTheAcceptedPackageGrantsEveryKnownCapability(): void
    {
        $baseline = ServiceTier::Free->baselineFeatures();

        foreach (Capability::cases() as $capability) {
            self::assertContains($capability->value, $baseline, $capability->value);
        }

        self::assertCount(count(Capability::cases()), $baseline);
    }

    /**
     * The half that matters most: free of charge is still not free of licence.
     * An installation that has activated nothing gets nothing.
     */
    public function testAnUnactivatedInstallationGetsNoCapabilityAtAll(): void
    {
        $store = new InMemoryPackageStore();
        $policy = $this->policyFor($store);

        $decision = $policy->decision();

        self::assertFalse($decision->granted);
        self::assertSame(CapabilityDenial::NotActivated, $decision->denial);

        foreach (Capability::cases() as $capability) {
            self::assertFalse($policy->allows($capability), $capability->value);
        }
    }

    /** Activated, and everything is available. */
    public function testAnActivatedInstallationGetsEveryCapability(): void
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage();
        $policy = $this->policyFor($store);

        self::assertTrue($policy->decision()->granted);

        foreach (Capability::cases() as $capability) {
            self::assertTrue($policy->allows($capability), $capability->value);
        }
    }

    /**
     * Removing the licence has to put the installation back exactly where it
     * started, not leave a capability behind.
     */
    public function testRemovingTheLicenceRestoresTheUnlicensedState(): void
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage();
        $policy = $this->policyFor($store);

        self::assertTrue($policy->decision()->granted);

        $store->package = null;
        $policy->reset();

        self::assertFalse($policy->decision()->granted);

        foreach (Capability::cases() as $capability) {
            self::assertFalse($policy->allows($capability), $capability->value);
        }
    }

    /**
     * A licence with an end date is not this product's licence, whether it is
     * still running or already over.
     */
    public function testATimeLimitedLicenceNeverGrantsAnything(): void
    {
        foreach ([self::NOW + 2592000, self::NOW - 10] as $expiresAt) {
            $store = new InMemoryPackageStore();
            $store->package = $this->factory->sealedPackage([
                'license_lifetime' => false,
                'license_expires_at' => $expiresAt,
            ]);
            $policy = $this->policyFor($store);

            self::assertSame(CapabilityDenial::TermNotSupported, $policy->decision()->denial);

            foreach (Capability::cases() as $capability) {
                self::assertFalse($policy->allows($capability), $capability->value);
            }
        }
    }

    /** There is no state below this one, so nothing can fall back to it. */
    public function testNoDecisionCanReportAFallbackTier(): void
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage();
        $policy = $this->policyFor($store);

        self::assertSame('granted', $policy->decision()->statusLabel());
        self::assertFalse(property_exists($policy->decision(), 'freeFallback'));
    }

    private function policyFor(InMemoryPackageStore $store): CapabilityPolicy
    {
        return new CapabilityPolicy(
            $store,
            $this->factory->identity($store),
            new CanonicalHost(),
            new FrozenClock(self::NOW),
        );
    }
}
