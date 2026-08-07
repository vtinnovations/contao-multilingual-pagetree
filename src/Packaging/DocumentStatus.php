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

namespace Vtinnovations\ContaoMultilingualPagetree\Packaging;

/**
 * The status the issuer signed into the document.
 *
 * This is the authoritative revocation channel: a revoked or suspended
 * entitlement is delivered as a normally signed document with a newer version,
 * so it cannot be undone by replaying the previous package.
 */
enum DocumentStatus: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Suspended = 'suspended';
    case Invalid = 'invalid';

    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
