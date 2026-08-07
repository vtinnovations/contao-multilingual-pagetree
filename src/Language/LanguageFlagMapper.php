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

namespace Vtinnovations\ContaoMultilingualPagetree\Language;

/** One deterministic language-to-local-flag mapping for backend and frontend. */
final class LanguageFlagMapper
{
    /** Flags shipped by this bundle and therefore safe for frontend rendering. */
    public const AVAILABLE_FLAGS = ['at', 'br', 'de', 'en', 'es', 'fr', 'gb', 'it', 'ja', 'jp', 'nl', 'pl', 'pt', 'ru', 'us', 'zh'];

    private const DEFAULT_FLAGS = [
        'de' => 'de',
        'en' => 'gb',
        'es' => 'es',
        'fr' => 'fr',
        'it' => 'it',
        'ja' => 'jp',
        'nl' => 'nl',
        'pl' => 'pl',
        'pt' => 'pt',
        'ru' => 'ru',
        // Chinese has no unambiguous country default.
        'zh' => '',
    ];

    public function defaultFlag(string $language): string
    {
        $language = str_replace('_', '-', strtolower(trim($language)));
        $parts = explode('-', $language, 2);
        $region = $parts[1] ?? '';

        if (in_array($region, self::AVAILABLE_FLAGS, true)) {
            return $region;
        }

        return self::DEFAULT_FLAGS[$parts[0]] ?? '';
    }

    public function isAvailable(string $flag): bool
    {
        return in_array(strtolower(trim($flag)), self::AVAILABLE_FLAGS, true);
    }
}
