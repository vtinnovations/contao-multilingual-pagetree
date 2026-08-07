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

namespace Vtinnovations\ContaoMultilingualPagetree\Support;

/**
 * The pinned public verification material of this build.
 *
 * Only public material ever appears here; the matching private keys stay with
 * the issuing infrastructure. The material is a trust anchor, so it is a code
 * constant: no setting, no database row, no request value and no remote answer
 * can add, replace or remove an entry. An empty list is still a valid state and
 * makes every signed workflow fail closed - it never becomes an unsigned
 * fallback. `tools/check-release-material.php` refuses to pass a release build
 * whose material is empty, malformed or does not reproduce the recorded
 * fingerprint.
 *
 * Each key is stored as an ordered list of fragments that are concatenated at
 * runtime, so the release build can transform the fragments without touching a
 * single call site. The assembly is plain concatenation and never evaluates
 * data.
 *
 * `fingerprint` is the SHA-256 of the assembled raw public key. It is a build
 * and audit check that the fragments still reassemble the approved bytes; it is
 * never a substitute for the key or for a signature.
 *
 * Rotation: add the new key id with `retired_at = null`, set `retired_at` on the
 * previous key to the moment rotation started, and drop it once the window has
 * passed. Emergency revocation is the same change with a window of 0, shipped
 * as a patch release. A replacement is never accepted from the channel itself.
 *
 * @internal this class is not a supported extension point
 */
final class PinnedMaterial
{
    /**
     * @var array<string, array{scheme: string, parts: list<string>, retired_at: int|null, window?: int, fingerprint?: string}>
     */
    private const KEYS = [
        'vtone-2026a' => [
            'scheme' => 'ed25519',
            'parts' => ['qllgm+66FUVBFJ3O68ICFG', '8b37dR+9jMfr1+4/pSygE='],
            'retired_at' => null,
            'fingerprint' => 'edcd614e70c59ce092ee08b2541b2aa226598dcf721e3ef36e8bfd7a6ea38316',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @return list<VerificationKey>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::KEYS as $keyId => $definition) {
            $scheme = SignatureScheme::tryFromValue($definition['scheme'] ?? null);

            if (null === $scheme) {
                continue;
            }

            $parts = $definition['parts'] ?? [];
            $key = VerificationKey::create(
                (string) $keyId,
                $scheme,
                is_array($parts) ? implode('', array_map('strval', $parts)) : '',
                $definition['retired_at'] ?? null,
                $definition['window'] ?? VerificationKey::DEFAULT_WINDOW,
            );

            if (null !== $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Number of entries declared, including entries this build rejected. */
    public static function declaredCount(): int
    {
        return count(self::KEYS);
    }

    /**
     * The fingerprints recorded for the declared entries, indexed by key id.
     *
     * Used by the release check to prove the fragments still reassemble the
     * approved bytes. Entries without a recorded fingerprint are reported by
     * that check rather than silently accepted.
     *
     * @return array<string, string>
     */
    public static function declaredFingerprints(): array
    {
        $fingerprints = [];

        foreach (self::KEYS as $keyId => $definition) {
            $recorded = $definition['fingerprint'] ?? null;

            if (is_string($recorded) && 1 === preg_match('/^[0-9a-f]{64}$/', $recorded)) {
                $fingerprints[(string) $keyId] = $recorded;
            }
        }

        return $fingerprints;
    }
}
