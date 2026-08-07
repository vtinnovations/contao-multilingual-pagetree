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

namespace Vtinnovations\ContaoMultilingualPagetree\Url;

/**
 * The protocol a language record forces, or inheritance from the website root.
 *
 * The stored value is deliberately stable and human readable: an empty string
 * means "inherit", so an existing row that has never been edited keeps the
 * behaviour it had before the field existed.
 */
enum ProtocolMode: string
{
    case Inherit = '';
    case Https = 'https';
    case Http = 'http';

    /**
     * Anything unknown, mistyped or migrated from an older row inherits. The
     * protocol is never guessed from a request.
     */
    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            return self::Inherit;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Inherit;
    }

    public function isInherited(): bool
    {
        return self::Inherit === $this;
    }

    /**
     * The scheme this mode forces, or null while it inherits.
     */
    public function scheme(): ?string
    {
        return self::Inherit === $this ? null : $this->value;
    }

    /**
     * @return list<string>
     */
    public static function storedValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
