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

    /**
     * The product has one entitlement and it withholds nothing. A "free" tier
     * here is a price, not a reduced feature set.
     */
    public function testTheLifetimeFreeStateGrantsEveryCapability(): void
    {
        $decision = $this->policy()->decision();

        self::assertTrue($decision->granted);
        self::assertSame(ServiceTier::Free, $decision->tier);
        self::assertTrue($decision->lifetime);
        self::assertNull($decision->expiresAt);

        foreach (Capability::cases() as $capability) {
            self::assertTrue($decision->allows($capability), $capability->value);
        }
    }

    /** `free` is the only package this product accepts. */
    public function testAPaidPackageIsNotAcceptedByThisProduct(): void
    {
        self::assertNull(ServiceTier::tryFromValue('pro'));
        self::assertNull(ServiceTier::tryFromValue('trial'));
        self::assertSame(ServiceTier::Free, ServiceTier::tryFromValue('free'));
        self::assertSame([ServiceTier::Free], ServiceTier::cases());
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

    /**
     * A time-limited document is refused outright rather than honoured until it
     * runs out, whether it is still inside its window or already past it. There
     * is no tier below this product's, so `free_available` cannot rescue it.
     *
     * @dataProvider timeLimitedDocuments
     */
    public function testATimeLimitedStateIsRefusedWhateverItsWindow(int $expiresAt, bool $freeAvailable): void
    {
        $decision = $this->policy([
            'license_lifetime' => false,
            'license_expires_at' => $expiresAt,
            'free_available' => $freeAvailable,
        ])->decision();

        self::assertFalse($decision->granted);
        self::assertSame(CapabilityDenial::TermNotSupported, $decision->denial);

        foreach (Capability::cases() as $capability) {
            self::assertFalse($decision->allows($capability), $capability->value);
        }
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function timeLimitedDocuments(): iterable
    {
        yield 'still running, fallback offered' => [self::NOW + 2592000, true];
        yield 'still running, no fallback' => [self::NOW + 2592000, false];
        yield 'already over, fallback offered' => [self::NOW - 10, true];
        yield 'already over, no fallback' => [self::NOW - 10, false];
    }

    public function testAStateThatHasNotStartedYetGrantsNothing(): void
    {
        $decision = $this->policy([
            'license_starts_at' => self::NOW + 100,
        ])->decision();

        self::assertSame(CapabilityDenial::NotYetValid, $decision->denial);
    }

    public function testTheLifetimeStateNeverExpires(): void
    {
        $policy = $this->policy([], PackageFactory::HOST, self::NOW + 10 * 365 * 86400);

        self::assertTrue($policy->decision()->granted);
        self::assertTrue($policy->decision()->lifetime);
        self::assertTrue($policy->decision()->allows(Capability::IntegrityRepair));
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

    /**
     * A withdrawn entitlement stays withdrawn. `free_available` must not turn it
     * back on, because this product's only tier *is* the free one.
     */
    public function testAnExpiredStatusGrantsNothingEvenWithFallbackOffered(): void
    {
        $decision = $this->policy([
            'validation_status' => 'expired',
            'free_available' => true,
        ])->decision();

        self::assertFalse($decision->granted);
        self::assertSame(CapabilityDenial::StatusNotValid, $decision->denial);
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
            'license_features' => ['some_future_feature'],
        ])->decision();

        self::assertTrue($decision->granted);
        self::assertSame(Capability::cases(), $decision->capabilities);
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
