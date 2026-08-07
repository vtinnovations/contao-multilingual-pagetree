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

use Psr\Log\LoggerInterface;

/**
 * Verifies a detached signature against a pinned public key.
 *
 * Everything that can go wrong - unknown key id, retired key, unknown scheme,
 * missing runtime support, malformed Base64, wrong signature length, a verifier
 * that throws - produces the same answer: false. There is no path on which a
 * missing crypto extension silently turns verification off.
 */
final class DetachedSignature
{
    public function __construct(
        private readonly KeyDirectory $keys,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function verify(string $canonicalInput, string $signatureBase64, string $keyId, string $schemeId, int $now): bool
    {
        $scheme = SignatureScheme::tryFromValue($schemeId);

        if (null === $scheme) {
            $this->logger?->warning('Contao Multilingual Pagetree: an unsupported signature scheme was offered.');

            return false;
        }

        if (!$scheme->isAvailable()) {
            // Fail closed: a runtime that cannot verify must not accept anything.
            $this->logger?->error('Contao Multilingual Pagetree: this runtime cannot verify the required signature scheme.');

            return false;
        }

        $key = $this->keys->find($keyId, $now);

        if (null === $key) {
            $this->logger?->warning('Contao Multilingual Pagetree: no pinned key matches the offered key id.');

            return false;
        }

        if ($key->scheme !== $scheme) {
            $this->logger?->warning('Contao Multilingual Pagetree: the offered scheme does not match the pinned key.');

            return false;
        }

        $signature = base64_decode($signatureBase64, true);

        if (false === $signature || strlen($signature) !== $scheme->signatureLength()) {
            return false;
        }

        try {
            return match ($scheme) {
                SignatureScheme::Ed25519 => sodium_crypto_sign_verify_detached($signature, $canonicalInput, $key->rawKey),
            };
        } catch (\Throwable) {
            $this->logger?->error('Contao Multilingual Pagetree: signature verification could not run.');

            return false;
        }
    }

    /**
     * Verifies where the scheme is not carried in the input itself.
     *
     * The scheme is then taken from the pinned key rather than from anything the
     * remote side sent, so it cannot be negotiated downwards.
     */
    public function verifyWithPinnedScheme(string $canonicalInput, string $signatureBase64, string $keyId, int $now): bool
    {
        $key = $this->keys->find($keyId, $now);

        if (null === $key) {
            $this->logger?->warning('Contao Multilingual Pagetree: no pinned key matches the offered key id.');

            return false;
        }

        return $this->verify($canonicalInput, $signatureBase64, $keyId, $key->scheme->value, $now);
    }

    /**
     * Verifies material that does not name the key that signed it.
     *
     * The licence document is such a case, so every currently usable pinned key
     * is tried until one succeeds. This widens nothing: the candidates are the
     * same pinned keys, and an unpinned key still verifies nothing.
     */
    public function verifyWithAnyKey(string $canonicalInput, string $signatureBase64, int $now): bool
    {
        $verified = false;

        foreach ($this->keys->usableAt($now) as $key) {
            // No early exit: the loop always runs to the end so the number of
            // candidates tried does not depend on which key matched.
            $verified = $this->verify($canonicalInput, $signatureBase64, $key->keyId, $key->scheme->value, $now) || $verified;
        }

        return $verified;
    }

    /** Whether this build has any material to verify against at all. */
    public function isOperational(): bool
    {
        return !$this->keys->isEmpty();
    }

    public function hasUsableKey(string $keyId, int $now): bool
    {
        return null !== $this->keys->find($keyId, $now);
    }

    /** How many keys this build accepted. Safe to log; contents never are. */
    public function keyCount(): int
    {
        return $this->keys->count();
    }

    /** The class actually supplying the material, for deployment diagnostics. */
    public function directoryClass(): string
    {
        return $this->keys::class;
    }
}
