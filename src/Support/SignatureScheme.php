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
 * The allowlist of detached-signature schemes this build accepts.
 *
 * An unknown identifier is never "probably fine": it is refused, so a downgrade
 * to a weaker or unimplemented scheme cannot be negotiated by the remote side.
 * The string values are part of the wire contract and must not be renamed
 * without a coordinated change on the issuing side.
 */
enum SignatureScheme: string
{
    case Ed25519 = 'ed25519';

    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtolower($value)) : null;
    }

    /** Raw public key length in bytes. */
    public function publicKeyLength(): int
    {
        return match ($this) {
            self::Ed25519 => 32,
        };
    }

    /** Raw detached signature length in bytes. */
    public function signatureLength(): int
    {
        return match ($this) {
            self::Ed25519 => 64,
        };
    }

    /** Whether this runtime can actually verify the scheme. */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::Ed25519 => function_exists('sodium_crypto_sign_verify_detached'),
        };
    }
}
