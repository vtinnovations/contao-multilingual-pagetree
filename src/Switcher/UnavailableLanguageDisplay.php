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

namespace Vtinnovations\ContaoMultilingualPagetree\Switcher;

/**
 * How the language switcher presents languages the current resource is not
 * available in.
 *
 * Any invalid or missing persisted value normalises to "hide", which is the
 * behaviour the switcher had before this setting existed.
 */
enum UnavailableLanguageDisplay: string
{
    case Hide = 'hide';
    case Disabled = 'disabled';

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            return self::Hide;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Hide;
    }

    public function showsUnavailable(): bool
    {
        return self::Disabled === $this;
    }
}
