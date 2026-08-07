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

namespace Vtinnovations\ContaoMultilingualPagetree\Metadata;

/**
 * Formats a configured language code as a valid hreflang value.
 *
 * Underscores become hyphens, the language subtag is lowercased and a region
 * subtag is uppercased. Codes that are not a plain language or language-region
 * combination are rejected instead of being guessed at, so an unrelated custom
 * code is never silently turned into another language.
 */
final class HreflangCodeFormatter
{
    public function format(?string $language): ?string
    {
        if (!is_string($language)) {
            return null;
        }

        $language = trim($language);

        if ('' === $language) {
            return null;
        }

        if (!preg_match('/^([A-Za-z]{2,3})(?:[_-]([A-Za-z]{2}|[0-9]{3}))?$/', $language, $matches)) {
            return null;
        }

        $code = strtolower($matches[1]);

        if (isset($matches[2]) && '' !== $matches[2]) {
            $code .= '-'.strtoupper($matches[2]);
        }

        return $code;
    }

    public function isValid(?string $language): bool
    {
        return null !== $this->format($language);
    }
}
