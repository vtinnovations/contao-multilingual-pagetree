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

/**
 * Content localisation strategy of one configured non-default site language.
 *
 * The default/source language never carries a mode; any missing or invalid
 * persisted value normalises to "connected", which is how installations behaved
 * before this setting existed.
 */
enum ContentTranslationMode: string
{
    /** Source articles and content elements stay authoritative for structure. */
    case Connected = 'connected';

    /** The language owns an independent article and content structure. */
    case Free = 'free';

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            return self::Connected;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Connected;
    }

    public function isFree(): bool
    {
        return self::Free === $this;
    }

    public function isConnected(): bool
    {
        return self::Connected === $this;
    }
}
