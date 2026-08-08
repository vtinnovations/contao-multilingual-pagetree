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
use Vtinnovations\ContaoMultilingualPagetree\Support\PinnedMaterial;
use Vtinnovations\ContaoMultilingualPagetree\Support\SignatureScheme;
use Vtinnovations\ContaoMultilingualPagetree\Support\VerificationKey;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

/**
 * The material this build actually ships, and what happens when it is wrong.
 *
 * These tests never assert the key bytes themselves: they assert that the
 * shipped fragments reassemble to the fingerprint recorded beside them, which is
 * what a reviewer can check against the approved record without the key being
 * repeated in yet another file.
 */
final class PinnedMaterialTest extends TestCase
{
    /** The active key id of the deployment profile this build targets. */
    private const ACTIVE_KEY_ID = 'vtone-2026a';

    public function testTheProductionRingIsNotEmpty(): void
    {
        self::assertGreaterThan(0, PinnedMaterial::declaredCount(), 'A distributable build must pin verification material.');
        self::assertFalse(KeyDirectory::pinned()->isEmpty());
    }

    /** Every declared entry survived structural validation. */
    public function testEveryDeclaredEntryIsUsable(): void
    {
        self::assertSame(PinnedMaterial::declaredCount(), KeyDirectory::pinned()->count());
    }

    public function testTheActiveKeyIdIsPresentAndUsable(): void
    {
        $directory = KeyDirectory::pinned();

        self::assertContains(self::ACTIVE_KEY_ID, $directory->keyIds());
        self::assertNotNull($directory->find(self::ACTIVE_KEY_ID, PackageFactory::NOW));
    }

    public function testTheActiveKeyUsesTheApprovedAlgorithmAndLength(): void
    {
        $key = KeyDirectory::pinned()->find(self::ACTIVE_KEY_ID, PackageFactory::NOW);

        self::assertNotNull($key);
        self::assertSame(SignatureScheme::Ed25519, $key->scheme);
        self::assertSame(32, \strlen($key->rawKey));
    }

    /** The shipped fragments still reassemble the approved bytes. */
    public function testEveryKeyReproducesItsRecordedFingerprint(): void
    {
        $fingerprints = PinnedMaterial::declaredFingerprints();

        self::assertNotSame([], $fingerprints, 'Material without a recorded fingerprint cannot be proven.');

        foreach (PinnedMaterial::keys() as $key) {
            self::assertArrayHasKey($key->keyId, $fingerprints, $key->keyId.' has no recorded fingerprint.');
            self::assertSame($fingerprints[$key->keyId], $key->fingerprint(), $key->keyId.' no longer reassembles.');
        }
    }

    /** Only public material ever ships. */
    public function testNoPrivateMaterialIsPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Support/PinnedMaterial.php');

        foreach (['PRIVATE KEY', 'sodium_crypto_sign_secretkey', 'BEGIN OPENSSH', 'BEGIN RSA'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    /** An empty ring verifies nothing; it never becomes an unsigned fallback. */
    public function testAnEmptyRingVerifiesNothing(): void
    {
        $signatures = new DetachedSignature(new KeyDirectory([]));

        self::assertFalse($signatures->isOperational());
        self::assertSame(0, $signatures->keyCount());
        self::assertFalse($signatures->hasUsableKey(self::ACTIVE_KEY_ID, PackageFactory::NOW));
        self::assertFalse($signatures->verify('anything', base64_encode(str_repeat("\0", 64)), self::ACTIVE_KEY_ID, 'ed25519', PackageFactory::NOW));
        self::assertFalse($signatures->verifyWithAnyKey('anything', base64_encode(str_repeat("\0", 64)), PackageFactory::NOW));
    }

    /**
     * Placeholder and malformed material is rejected at construction, so it can
     * never reach the directory and be counted as trust.
     *
     * @dataProvider unusableMaterial
     */
    public function testMalformedMaterialIsRejected(string $keyId, string $base64): void
    {
        self::assertNull(VerificationKey::create($keyId, SignatureScheme::Ed25519, $base64));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableMaterial(): iterable
    {
        yield 'empty key' => ['vtone-2026a', ''];
        yield 'placeholder text' => ['vtone-2026a', '<first half of the Base64 public key>'];
        yield 'not base64' => ['vtone-2026a', '!!!!not-base64!!!!'];
        yield 'wrong length' => ['vtone-2026a', base64_encode(str_repeat("\1", 16))];
        yield 'too long' => ['vtone-2026a', base64_encode(str_repeat("\1", 64))];
        yield 'empty key id' => ['', base64_encode(str_repeat("\1", 32))];
        yield 'unusable key id' => ['has spaces', base64_encode(str_repeat("\1", 32))];
    }

    /** A key the ring does not contain verifies nothing, whatever it signed. */
    public function testAKeyOutsideTheRingIsNeverAccepted(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('This runtime cannot create signatures.');
        }

        $factory = new PackageFactory('some-other-key');
        $signature = $factory->sign('message');

        $signatures = new DetachedSignature(KeyDirectory::pinned());

        self::assertFalse($signatures->hasUsableKey('some-other-key', PackageFactory::NOW));
        self::assertFalse($signatures->verify('message', $signature, 'some-other-key', 'ed25519', PackageFactory::NOW));
        // Nor by claiming the active id: the material simply does not match.
        self::assertFalse($signatures->verify('message', $signature, self::ACTIVE_KEY_ID, 'ed25519', PackageFactory::NOW));
        self::assertFalse($signatures->verifyWithAnyKey('message', $signature, PackageFactory::NOW));
    }

    /** A scheme the pinned key does not use is refused rather than negotiated. */
    public function testAnAlgorithmMismatchIsRefused(): void
    {
        $signatures = new DetachedSignature(KeyDirectory::pinned());

        self::assertFalse($signatures->verify('message', base64_encode(str_repeat("\0", 64)), self::ACTIVE_KEY_ID, 'rsa-sha256', PackageFactory::NOW));
        self::assertFalse($signatures->verify('message', base64_encode(str_repeat("\0", 64)), self::ACTIVE_KEY_ID, '', PackageFactory::NOW));
    }

    /** A retired key stops verifying once its rotation window has closed. */
    public function testARetiredKeyStopsBeingUsable(): void
    {
        $key = VerificationKey::create('rotating', SignatureScheme::Ed25519, base64_encode(str_repeat("\1", 32)), PackageFactory::NOW, 100);

        self::assertNotNull($key);

        $directory = new KeyDirectory([$key]);

        self::assertNotNull($directory->find('rotating', PackageFactory::NOW + 100));
        self::assertNull($directory->find('rotating', PackageFactory::NOW + 101));
        self::assertSame([], $directory->usableAt(PackageFactory::NOW + 101));
    }
}
