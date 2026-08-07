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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Support;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Support\DetachedSignature;
use Vtinnovations\ContaoMultilingualPagetree\Support\KeyDirectory;
use Vtinnovations\ContaoMultilingualPagetree\Support\SignatureScheme;
use Vtinnovations\ContaoMultilingualPagetree\Support\VerificationKey;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

final class DetachedSignatureTest extends TestCase
{
    private const NOW = PackageFactory::NOW;

    protected function setUp(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('The sodium extension is not available.');
        }
    }

    public function testAValidSignatureVerifies(): void
    {
        $factory = new PackageFactory();
        $signatures = $factory->signatures();

        self::assertTrue($signatures->verify('payload', $factory->sign('payload'), PackageFactory::KEY_ID, 'ed25519', self::NOW));
    }

    public function testATamperedInputFails(): void
    {
        $factory = new PackageFactory();

        self::assertFalse(
            $factory->signatures()->verify('payload!', $factory->sign('payload'), PackageFactory::KEY_ID, 'ed25519', self::NOW),
        );
    }

    public function testAnotherKeysSignatureFails(): void
    {
        $mine = new PackageFactory();
        $other = new PackageFactory();

        self::assertFalse(
            $mine->signatures()->verify('payload', $other->sign('payload'), PackageFactory::KEY_ID, 'ed25519', self::NOW),
        );
    }

    public function testAnUnknownKeyIdFails(): void
    {
        $factory = new PackageFactory();

        self::assertFalse(
            $factory->signatures()->verify('payload', $factory->sign('payload'), 'not-pinned', 'ed25519', self::NOW),
        );
    }

    public function testAnUnknownSchemeFails(): void
    {
        $factory = new PackageFactory();

        self::assertFalse(
            $factory->signatures()->verify('payload', $factory->sign('payload'), PackageFactory::KEY_ID, 'rsa-pkcs1', self::NOW),
        );
        self::assertNull(SignatureScheme::tryFromValue('rsa-pkcs1'));
    }

    /** An empty directory means "cannot verify", never "accept anything". */
    public function testAnEmptyDirectoryFailsClosed(): void
    {
        $factory = new PackageFactory();
        $signatures = new DetachedSignature(new KeyDirectory());

        self::assertFalse($signatures->isOperational());
        self::assertFalse($signatures->verify('payload', $factory->sign('payload'), PackageFactory::KEY_ID, 'ed25519', self::NOW));
    }

    public function testARetiredKeyStaysUsableInsideItsWindowOnly(): void
    {
        $factory = new PackageFactory();
        $retiredAt = self::NOW - 1000;

        $inside = new DetachedSignature($factory->keyDirectory($retiredAt, 2000));
        $outside = new DetachedSignature($factory->keyDirectory($retiredAt, 500));

        self::assertTrue($inside->verify('payload', $factory->sign('payload'), PackageFactory::KEY_ID, 'ed25519', self::NOW));
        self::assertFalse($outside->verify('payload', $factory->sign('payload'), PackageFactory::KEY_ID, 'ed25519', self::NOW));
    }

    public function testTheSchemeIsTakenFromThePinnedKeyWhenItIsNotCarriedInTheInput(): void
    {
        $factory = new PackageFactory();

        self::assertTrue(
            $factory->signatures()->verifyWithPinnedScheme('payload', $factory->sign('payload'), PackageFactory::KEY_ID, self::NOW),
        );
        self::assertFalse(
            $factory->signatures()->verifyWithPinnedScheme('payload', $factory->sign('payload'), 'not-pinned', self::NOW),
        );
    }

    public function testMalformedKeyMaterialIsRejectedAtConstruction(): void
    {
        self::assertNull(VerificationKey::create('key', SignatureScheme::Ed25519, base64_encode('too-short')));
        self::assertNull(VerificationKey::create('', SignatureScheme::Ed25519, base64_encode(str_repeat('a', 32))));
        self::assertNull(VerificationKey::create('bad key id!', SignatureScheme::Ed25519, base64_encode(str_repeat('a', 32))));
        self::assertNotNull(VerificationKey::create('key', SignatureScheme::Ed25519, base64_encode(str_repeat('a', 32))));
    }

    /** A shipped build must never contain private material or a shared secret. */
    public function testNoPrivateMaterialIsShipped(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Support/PinnedMaterial.php');

        self::assertStringNotContainsString('PRIVATE KEY', $source);
        self::assertStringNotContainsString('sodium_crypto_sign_secretkey', $source);
        self::assertStringNotContainsString('secret', strtolower($source));
    }
}
