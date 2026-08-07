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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Packaging;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageFormatException;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\ServiceTier;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

/**
 * The verification chain: seal signature, exact-byte digest, document signature.
 */
final class PackageReaderTest extends TestCase
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

    public function testACompletePackageIsAccepted(): void
    {
        $package = $this->factory->wirePackage();
        $sealed = $this->factory->reader()->readPackage($package['payload'], $package['integrity'], self::NOW);

        self::assertSame($package['bytes'], $sealed->bytes, 'The exact bytes must be preserved, never re-serialised.');
        self::assertSame(ServiceTier::Free, $sealed->document->tier);
        self::assertSame('example.com', $sealed->document->boundHost);
        self::assertSame(7, $sealed->document->version);
    }

    /** Whitespace is not "insignificant": the digest covers the exact bytes. */
    public function testAWhitespaceOnlyMutationBreaksTheDigest(): void
    {
        $package = $this->factory->wirePackage();
        $mutated = base64_encode($package['bytes']." ");

        $this->expectException(PackageFormatException::class);
        $this->factory->reader()->readPackage($mutated, $package['integrity'], self::NOW);
    }

    public function testEditingTheDocumentAndRecalculatingTheDigestIsRejected(): void
    {
        $package = $this->factory->wirePackage();
        $fields = json_decode($package['bytes'], true);

        // A structurally valid edit on purpose: it must be the signature that
        // stops this, not a shape check the attacker could simply have avoided.
        $fields['license_version'] = 99;
        $edited = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // The attacker also recalculates the digest, but cannot re-sign the seal.
        $seal = $package['integrity'];
        $seal['license_md5'] = md5($edited);

        $this->expectException(PackageFormatException::class);
        $this->factory->reader()->readPackage(base64_encode($edited), $seal, self::NOW);
    }

    public function testAResealedDocumentStillFailsTheDocumentSignature(): void
    {
        $package = $this->factory->wirePackage();
        $fields = json_decode($package['bytes'], true);
        $fields['license_domain'] = 'attacker.test';
        $edited = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Even with a correctly signed seal over the edited bytes - which only
        // the key holder could produce - the document signature no longer fits.
        $seal = $this->factory->seal($edited);

        $this->expectException(PackageFormatException::class);
        $this->factory->reader()->readPackage(base64_encode($edited), $seal, self::NOW);
    }

    public function testASealFromAnotherVersionCannotBeReattached(): void
    {
        $current = $this->factory->wirePackage(['license_version' => 7]);
        $other = $this->factory->wirePackage(['license_version' => 8]);

        $this->expectException(PackageFormatException::class);
        $this->factory->reader()->readPackage($current['payload'], $other['integrity'], self::NOW);
    }

    /**
     * @dataProvider malformedPayloads
     */
    public function testMalformedPayloadsAreRejected(mixed $payload): void
    {
        $package = $this->factory->wirePackage();

        $this->expectException(PackageFormatException::class);
        $this->factory->reader()->readPackage($payload, $package['integrity'], self::NOW);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'not base64' => ['{{{not base64}}}'];
        yield 'non canonical base64' => ["eyJhIjoxfQ==\n"];
        yield 'array' => [['a']];
    }

    public function testAMissingSealIsRejected(): void
    {
        $package = $this->factory->wirePackage();

        $this->expectException(PackageFormatException::class);
        $this->factory->reader()->readPackage($package['payload'], null, self::NOW);
    }

    /**
     * @dataProvider invalidDocuments
     *
     * @param array<string, mixed> $overrides
     */
    public function testInvalidDocumentsAreRejected(array $overrides): void
    {
        $this->expectException(PackageFormatException::class);
        $this->factory->sealedPackage($overrides);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'wrong schema' => [['schema_version' => 1]];
        yield 'other project' => [['project' => 'Something Else']];
        yield 'other slug' => [['project_slug' => 'other-slug']];
        yield 'unknown tier' => [['license_package' => 'enterprise']];
        yield 'unknown status' => [['validation_status' => 'maybe']];
        yield 'wildcard host' => [['license_domain' => '*.example.com']];
        yield 'ip host' => [['license_domain' => '192.0.2.10']];
        yield 'non lifetime without expiry' => [['license_lifetime' => false, 'license_expires_at' => null]];
        yield 'lifetime with expiry' => [['license_lifetime' => true, 'license_expires_at' => 1787472547]];
        yield 'expiry before start' => [['license_lifetime' => false, 'license_expires_at' => 1]];
        yield 'string version' => [['license_version' => '7']];
        yield 'float timestamp' => [['license_issued_at' => 1784794147.0]];
        yield 'feature list as map' => [['license_features' => ['a' => 'b']]];
        yield 'unusable feature id' => [['license_features' => ['Not Valid']]];
        yield 'empty key' => [['license_key' => '']];
    }

    public function testALifetimeDocumentIsAccepted(): void
    {
        $sealed = $this->factory->sealedPackage();

        self::assertTrue($sealed->document->lifetime);
        self::assertNull($sealed->document->expiresAt);
        self::assertSame(ServiceTier::Free, $sealed->document->tier);
    }

    /**
     * A package this product is not issued under is refused while the document
     * is still being parsed, so it can never reach an entitlement decision.
     *
     * @dataProvider foreignPackages
     */
    public function testAPackageThisProductIsNotSoldUnderIsRefused(string $package): void
    {
        $this->expectException(PackageFormatException::class);
        $this->factory->sealedPackage(['license_package' => $package]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignPackages(): iterable
    {
        yield 'paid tier' => ['pro'];
        yield 'trial tier' => ['trial'];
        yield 'enterprise tier' => ['enterprise'];
    }

    public function testStoredStateIsReVerifiedOnEveryRead(): void
    {
        $package = $this->factory->wirePackage();
        $sealJson = json_encode($package['integrity'], JSON_THROW_ON_ERROR);
        $reader = $this->factory->reader();

        self::assertSame($package['bytes'], $reader->readStored($package['bytes'], $sealJson, self::NOW)->bytes);

        $this->expectException(PackageFormatException::class);
        $reader->readStored($package['bytes']."\n", $sealJson, self::NOW);
    }

    public function testGrantedFeaturesAreTheUnionOfTierAndSignedList(): void
    {
        $package = $this->factory->sealedPackage([
            'license_features' => ['integrity_repair', 'a_later_feature'],
        ]);

        // `integrity_repair` is already in the baseline and must not be repeated;
        // an identifier outside it is appended, so the result is a real union.
        self::assertSame(
            ['translation_editing', 'translation_review', 'free_content_mode', 'integrity_repair', 'a_later_feature'],
            $package->document->grantedFeatures(),
        );
    }
}
