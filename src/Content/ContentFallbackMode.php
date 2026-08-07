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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

/** Canonical handling of a missing connected content translation. */
enum ContentFallbackMode: string
{
    case Strict = 'strict';
    case Fallback = 'fallback';

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value)
            ? (self::tryFrom(strtolower(trim($value))) ?? self::Fallback)
            : self::Fallback;
    }

    public function showsSourceWhenMissing(): bool
    {
        return self::Fallback === $this;
    }
}
