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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Support\KeyDirectory;
use Vtinnovations\ContaoMultilingualPagetree\Support\PinnedMaterial;
use Vtinnovations\ContaoMultilingualPagetree\Support\SignatureScheme;

/**
 * Guards the failure mode this product has already been bitten by once.
 *
 * An artefact whose pinned ring is empty is not broken in an obvious way: it
 * installs, it routes, it reaches the distribution service, it gets a perfectly
 * good answer back - and then correctly refuses it, because it has nothing to
 * check the signature against. The symptom is a build that "works" everywhere
 * except at the one step that matters.
 *
 * The runtime is right to fail closed there, so the defence has to sit earlier:
 * the ring must be provisioned in source, the build must refuse to produce an
 * artefact without it, the artefact check must confirm it survived the
 * transformation, and the pipeline must not be able to wave the check through.
 */
final class VerificationMaterialGateTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** The production ring is provisioned, not a placeholder. */
    public function testTheProductionRingIsPopulated(): void
    {
        $directory = KeyDirectory::pinned();

        self::assertFalse($directory->isEmpty(), 'A release must ship pinned verification material.');
        self::assertGreaterThan(0, $directory->count());
        self::assertSame(PinnedMaterial::declaredCount(), $directory->count(), 'Every declared entry must be usable.');
    }

    /** Every declared entry reassembles to the fingerprint recorded beside it. */
    public function testEveryPinnedKeyReassemblesToItsRecordedFingerprint(): void
    {
        $fingerprints = PinnedMaterial::declaredFingerprints();
        $keys = PinnedMaterial::keys();

        self::assertNotSame([], $keys);

        foreach ($keys as $key) {
            self::assertArrayHasKey($key->keyId, $fingerprints, $key->keyId.' has no recorded fingerprint.');
            self::assertSame(
                $fingerprints[$key->keyId],
                $key->fingerprint(),
                $key->keyId.' no longer reassembles to the approved material.',
            );
            self::assertSame(
                $key->scheme->publicKeyLength(),
                \strlen($key->rawKey),
                $key->keyId.' has the wrong raw key length for its scheme.',
            );
        }
    }

    /** Only public material is ever pinned. */
    public function testNoPrivateMaterialIsPinned(): void
    {
        // Comments are stripped first: the file explains *why* private keys stay
        // with the issuer, and that sentence must not be mistaken for one.
        $code = '';

        foreach (token_get_all((string) file_get_contents(self::ROOT.'/src/Support/PinnedMaterial.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach (['PRIVATE KEY', 'BEGIN OPENSSH', 'sodium_crypto_sign_secretkey', 'secretkey'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $code);
        }
    }

    /** The ring is a code constant: nothing outside the build can add to it. */
    public function testTheRingCannotBeInfluencedAtRuntime(): void
    {
        $material = (string) file_get_contents(self::ROOT.'/src/Support/PinnedMaterial.php');
        $directory = (string) file_get_contents(self::ROOT.'/src/Support/KeyDirectory.php');
        $services = (string) file_get_contents(self::ROOT.'/src/Resources/config/services.yaml');

        foreach ([$material, $directory] as $source) {
            self::assertStringNotContainsString('getParameter', $source);
            self::assertStringNotContainsString('$_ENV', $source);
            self::assertStringNotContainsString('getenv', $source);
            self::assertStringNotContainsString('file_get_contents', $source);
        }

        // The container builds the ring from the code constants and from nothing
        // else, so no configuration value can widen who is trusted.
        self::assertMatchesRegularExpression(
            '/Support\\\\KeyDirectory:\s*\n\s*factory: \[.*KeyDirectory.*, .pinned.\]/',
            $services,
        );
    }

    /** An empty ring stays fail-closed and never becomes an unsigned fallback. */
    public function testAnEmptyRingVerifiesNothing(): void
    {
        $empty = new KeyDirectory([]);

        self::assertTrue($empty->isEmpty());
        self::assertSame(0, $empty->count());
        self::assertNull($empty->find('vtone-2026a', 1784880547));
        self::assertSame([], $empty->usableAt(1784880547));
    }

    /** An unknown key id resolves to nothing even when the ring is populated. */
    public function testAnUnknownKeyIdIsNeverResolved(): void
    {
        $directory = KeyDirectory::pinned();

        self::assertNull($directory->find('vtone-does-not-exist', 1784880547));
        self::assertNull($directory->find('', 1784880547));
    }

    /** The scheme allowlist admits exactly one algorithm and no negotiation. */
    public function testTheSchemeAllowlistIsClosed(): void
    {
        self::assertNull(SignatureScheme::tryFromValue('rsa'));
        self::assertNull(SignatureScheme::tryFromValue('none'));
        self::assertNull(SignatureScheme::tryFromValue(null));
        self::assertSame(SignatureScheme::Ed25519, SignatureScheme::tryFromValue('ED25519'));
        self::assertSame(32, SignatureScheme::Ed25519->publicKeyLength());
        self::assertSame(64, SignatureScheme::Ed25519->signatureLength());
    }

    /** The release build refuses to run before the ring is proven. */
    public function testTheReleaseBuildRefusesAnUnprovenRing(): void
    {
        $build = (string) file_get_contents(self::ROOT.'/tools/build-release.php');

        self::assertStringContainsString('tools/check-release-material.php', $build);
        self::assertStringContainsString('Release build refused', $build);
        self::assertStringNotContainsString('--allow-empty', $build);

        // The check runs before anything is copied, so a build cannot get half
        // way and leave an unusable artefact behind.
        self::assertLessThan(
            strpos($build, 'removeDirectory($out);'),
            strpos($build, 'check-release-material.php'),
        );
    }

    /** The build re-proves the key after it re-splits the fragments. */
    public function testTheReleaseBuildRevalidatesTheRingAfterTransformation(): void
    {
        $build = (string) file_get_contents(self::ROOT.'/tools/build-release.php');

        self::assertStringContainsString('no longer reassembles to its recorded fingerprint after transformation', $build);
        self::assertStringContainsString('contains no pinned verification material', $build);
    }

    /** The artefact check confirms the shipped artefact can verify something. */
    public function testTheArtefactCheckRequiresUsableMaterial(): void
    {
        $verifier = (string) file_get_contents(self::ROOT.'/tools/verify-release-artefact.php');

        self::assertStringContainsString('ships no usable verification material', $verifier);
        self::assertStringContainsString('does not reassemble to its recorded fingerprint', $verifier);
    }

    /** The pipeline may not wave the material check through. */
    public function testThePipelineEnforcesTheMaterialCheck(): void
    {
        $workflow = (string) file_get_contents(self::ROOT.'/.github/workflows/compatibility.yml');

        self::assertStringContainsString('php tools/check-release-material.php', $workflow);
        self::assertStringNotContainsString('check-release-material.php --allow-empty', $workflow);
    }
}
