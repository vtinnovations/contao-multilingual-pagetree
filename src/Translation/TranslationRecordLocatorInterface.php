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

namespace Vtinnovations\ContaoMultilingualPagetree\Translation;

/**
 * Resolves the translation record belonging to a source record.
 */
interface TranslationRecordLocatorInterface
{
    /**
     * Returns the translation record for $sourceId in $language or null when no
     * translation exists or the lookup failed.
     *
     * $parentId is an optional hint that allows the implementation to pre-warm
     * the translations of all sibling records with a single query.
     */
    public function find(string $translationTable, int $sourceId, string $language, ?int $parentId = null): ?object;
}
