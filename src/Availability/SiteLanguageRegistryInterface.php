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

use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentFallbackMode;

/**
 * Reads the language configuration of one Contao root site.
 *
 * Every lookup is scoped to a root page id so configuration can never leak
 * between sites.
 */
interface SiteLanguageRegistryInterface
{
    /**
     * All published languages configured for the root site.
     *
     * @return list<SiteLanguage>
     */
    public function languages(int $rootPageId): array;

    public function defaultLanguage(int $rootPageId): string;

    public function isEnabled(int $rootPageId, string $language): bool;

    /**
     * The page-availability mode of a non-default language. The default
     * language and unknown languages always resolve to "fallback".
     */
    public function mode(int $rootPageId, string $language): PageAvailabilityMode;

    /**
     * The content localisation strategy of a non-default language. The default
     * language and unknown languages always resolve to "connected".
     */
    public function contentMode(int $rootPageId, string $language): ContentTranslationMode;

    public function contentFallbackMode(int $rootPageId, string $language): ContentFallbackMode;
}
