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
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\ServiceTier;
use Vtinnovations\ContaoMultilingualPagetree\Security\Capability;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityDenial;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FrozenClock;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

final class CapabilityPolicyTest extends TestCase
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

    public function testAValidPaidStateGrantsEveryCapability(): void
    {
        $decision = $this->policy()->decision();

        self::assertTrue($decision->granted);
        self::assertSame(ServiceTier::Pro, $decision->tier);
        self::assertFalse($decision->freeFallback);

        foreach (Capability::cases() as $capability) {
            self::assertTrue($decision->allows($capability), $capability->value);
        }
    }

    public function testNothingIsGrantedBeforeActivation(): void
    {
        $store = new InMemoryPackageStore();
        $policy = new CapabilityPolicy($store, $this->factory->identity($store), new CanonicalHost(), new FrozenClock(self::NOW));

        self::assertSame(CapabilityDenial::NotActivated, $policy->decision()->denial);
        self::assertFalse($policy->allows(Capability::TranslationEditing));
    }

    public function testUnreadableStateIsReportedButNeverRepaired(): void
    {
        $store = new InMemoryPackageStore();
        $store->unreadable = true;
        $policy = new CapabilityPolicy($store, $this->factory->identity($store), new CanonicalHost(), new FrozenClock(self::NOW));

        self::assertSame(CapabilityDenial::StateUnusable, $policy->decision()->denial);
    }

    /**
     * @dataProvider foreignHosts
     */
    public function testStateCopiedToAnotherHostGrantsNothing(string $boundHost, string $requestHost): void
    {
        $policy = $this->policy(['license_domain' => $boundHost], $requestHost);

        self::assertSame(CapabilityDenial::HostMismatch, $policy->decision()->denial);
        self::assertFalse($policy->allows(Capability::TranslationEditing));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foreignHosts(): iterable
    {
        yield 'apex state on www' => ['example.com', 'www.example.com'];
        yield 'www state on apex' => ['www.example.com', 'example.com'];
        yield 'apex state on subdomain' => ['example.com', 'shop.example.com'];
        yield 'subdomain state on apex' => ['shop.example.com', 'example.com'];
        yield 'sibling subdomain' => ['shop.example.com', 'staging.example.com'];
        yield 'nested subdomain' => ['shop.example.com', 'admin.shop.example.com'];
        yield 'unrelated host' => ['example.com', 'malicious-example.com'];
    }

    public function testTheExactHostIsAcceptedRegardlessOfRepresentation(): void
    {
        self::assertTrue($this->policy([], 'EXAMPLE.com')->decision()->granted);
    }

    /**
     * Being in the same signed set is not the same as being this scope's host.
     *
     * The second bound host has its own activation and its own stored state.
     * State bound to one of them does not run on the other just because the
     * licence covers both.
     */
    public function testASecondBoundHostIsNotServedByThisScopesState(): void
    {
        $policy = $this->policy([], PackageFactory::SECOND_HOST);

        self::assertSame(CapabilityDenial::HostMismatch, $policy->decision()->denial);
        self::assertFalse($policy->allows(Capability::TranslationEditing));
    }

    /**
     * Verified state from before the signed host set existed.
     *
     * It is preserved exactly as it is - it is still this scope's rollback
     * material - but it authorises nothing until a refresh has delivered the
     * set the issuer signs today. Inventing that set locally is the one thing
     * that must never happen here.
     */
    public function testStateFromBeforeTheSignedHostSetRequiresARefresh(): void
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->legacySealedPackage();
        $store->host = PackageFactory::HOST;
        $policy = new CapabilityPolicy($store, $this->factory->identity($store), new CanonicalHost(), new FrozenClock(self::NOW));

        $decision = $policy->decision();

        self::assertSame(CapabilityDenial::RefreshRequired, $decision->denial);
        self::assertSame('refresh_required', $decision->statusLabel());
        self::assertFalse($policy->allows(Capability::TranslationEditing));

        // Nothing was removed, rewritten or repaired on the way.
        self::assertNotNull($store->package);
        self::assertTrue($store->package->document->isLegacyHostBinding());
        self::assertSame(0, $store->writes);
    }

    public function testAnExpiredStateFallsBackToFreeWhenAuthorised(): void
    {
        $decision = $this->policy([
            'license_expires_at' => self::NOW - 10,
            'free_available' => true,
        ])->decision();

        self::assertTrue($decision->granted);
        self::assertTrue($decision->freeFallback);
        self::assertSame(ServiceTier::Free, $decision->tier);
        self::assertTrue($decision->allows(Capability::TranslationEditing));
        self::assertFalse($decision->allows(Capability::FreeContentMode));
        self::assertFalse($decision->allows(Capability::IntegrityRepair));
    }

    public function testAnExpiredStateWithoutFallbackGrantsNothing(): void
    {
        $decision = $this->policy([
            'license_expires_at' => self::NOW - 10,
            'free_available' => false,
        ])->decision();

        self::assertFalse($decision->granted);
        self::assertSame(CapabilityDenial::Expired, $decision->denial);
    }

    public function testAStateThatHasNotStartedYetGrantsNothing(): void
    {
        $decision = $this->policy([
            'license_starts_at' => self::NOW + 100,
            'license_expires_at' => self::NOW + 10000,
        ])->decision();

        self::assertSame(CapabilityDenial::NotYetValid, $decision->denial);
    }

    public function testALifetimeStateNeverExpires(): void
    {
        $policy = $this->policy([
            'license_lifetime' => true,
            'license_expires_at' => null,
        ], PackageFactory::HOST, self::NOW + 10 * 365 * 86400);

        self::assertTrue($policy->decision()->granted);
        self::assertTrue($policy->decision()->lifetime);
    }

    /**
     * @dataProvider revokedStatuses
     */
    public function testARevokedStateGrantsNothing(string $status): void
    {
        $decision = $this->policy(['validation_status' => $status])->decision();

        self::assertFalse($decision->granted);
        self::assertSame(CapabilityDenial::StatusNotValid, $decision->denial);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function revokedStatuses(): iterable
    {
        yield 'revoked' => ['revoked'];
        yield 'suspended' => ['suspended'];
        yield 'invalid' => ['invalid'];
    }

    public function testAnExpiredStatusUsesTheAuthorisedFreeFallback(): void
    {
        $decision = $this->policy([
            'validation_status' => 'expired',
            'free_available' => true,
        ])->decision();

        self::assertTrue($decision->granted);
        self::assertTrue($decision->freeFallback);
    }

    /**
     * Console commands and workers have no trusted request host; they validate
     * against the host that was proven when the state was written.
     */
    public function testNonHttpExecutionUsesThePersistedVerifiedHost(): void
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage();
        $store->host = PackageFactory::HOST;

        $policy = new CapabilityPolicy(
            $store,
            $this->factory->identity($store, null),
            new CanonicalHost(),
            new FrozenClock(self::NOW),
        );

        self::assertTrue($policy->decision()->granted);
    }

    public function testNonHttpExecutionWithoutAProvenHostGrantsNothing(): void
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage();

        $policy = new CapabilityPolicy(
            $store,
            $this->factory->identity($store, null),
            new CanonicalHost(),
            new FrozenClock(self::NOW),
        );

        self::assertSame(CapabilityDenial::HostUnknown, $policy->decision()->denial);
    }

    /** A copied state pair does not gain a foreign host record either. */
    public function testAPersistedHostFromAnotherInstallationDoesNotHelp(): void
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage(['license_domain' => 'example.com']);
        $store->host = 'www.example.com';

        $policy = new CapabilityPolicy(
            $store,
            $this->factory->identity($store, null),
            new CanonicalHost(),
            new FrozenClock(self::NOW),
        );

        self::assertSame(CapabilityDenial::HostMismatch, $policy->decision()->denial);
    }

    public function testTheDecisionIsEvaluatedOnceAndCanBeReset(): void
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage();
        $policy = new CapabilityPolicy($store, $this->factory->identity($store), new CanonicalHost(), new FrozenClock(self::NOW));

        self::assertSame($policy->decision(), $policy->decision());

        $policy->reset();
        $store->package = null;

        self::assertFalse($policy->decision()->granted);
    }

    /** An unknown feature identifier must not unlock an older gate. */
    public function testUnknownFeatureIdentifiersAreIgnored(): void
    {
        $decision = $this->policy([
            'license_package' => 'free',
            'license_features' => ['some_future_feature'],
        ])->decision();

        self::assertTrue($decision->granted);
        self::assertSame(
            [Capability::TranslationEditing, Capability::TranslationReview],
            $decision->capabilities,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function policy(array $overrides = [], string $requestHost = PackageFactory::HOST, int $now = self::NOW): CapabilityPolicy
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage($overrides);

        return new CapabilityPolicy(
            $store,
            $this->factory->identity($store, $requestHost),
            new CanonicalHost(),
            new FrozenClock($now),
        );
    }
}
