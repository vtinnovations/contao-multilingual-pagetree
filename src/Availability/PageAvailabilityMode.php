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

namespace Vtinnovations\ContaoMultilingualPagetree\Availability;

/**
 * Page-availability mode of a configured non-default site language.
 *
 * The default/fallback site language always uses the source page tree and does
 * not need a mode. Any invalid or missing persisted value normalises to
 * "fallback", which is the behaviour installations had before this setting
 * existed.
 */
enum PageAvailabilityMode: string
{
    case Strict = 'strict';
    case Fallback = 'fallback';

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            return self::Fallback;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Fallback;
    }

    public function isStrict(): bool
    {
        return self::Strict === $this;
    }
}
