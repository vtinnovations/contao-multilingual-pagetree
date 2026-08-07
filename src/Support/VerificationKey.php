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
 * One pinned public verification key.
 *
 * Only public key material ever exists in a distributed build; the matching
 * private keys stay with the issuing infrastructure. A retired key remains
 * usable until its rotation window closes, so material signed shortly before a
 * rotation keeps verifying.
 */
final class VerificationKey
{
    /** Default grace period after retirement, in seconds (30 days). */
    public const DEFAULT_WINDOW = 2592000;

    private function __construct(
        public readonly string $keyId,
        public readonly SignatureScheme $scheme,
        public readonly string $rawKey,
        public readonly ?int $retiredAt,
        public readonly int $rotationWindow,
    ) {
    }

    /**
     * @param string   $base64Key raw public key, Base64 encoded
     * @param int|null $retiredAt UTC timestamp at which rotation started
     * @param int      $window    seconds the key stays acceptable after retirement
     */
    public static function create(
        string $keyId,
        SignatureScheme $scheme,
        string $base64Key,
        ?int $retiredAt = null,
        int $window = self::DEFAULT_WINDOW,
    ): ?self {
        if ('' === $keyId || '' === $base64Key || 1 !== preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId)) {
            return null;
        }

        $raw = base64_decode($base64Key, true);

        if (false === $raw || strlen($raw) !== $scheme->publicKeyLength()) {
            return null;
        }

        return new self($keyId, $scheme, $raw, $retiredAt, max(0, $window));
    }

    public function isUsableAt(int $now): bool
    {
        return null === $this->retiredAt || $now <= $this->retiredAt + $this->rotationWindow;
    }

    /**
     * SHA-256 of the raw public key.
     *
     * A build-time and audit-time check that the stored fragments still
     * reassemble the approved bytes. It identifies the key; it never replaces
     * the key and never stands in for a signature check.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->rawKey);
    }
}
