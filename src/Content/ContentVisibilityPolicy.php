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

use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;

/**
 * The single rendering decision of point 9.
 *
 * For one active language and one resolved content mode it answers, for every
 * article or content record, whether that record belongs to the rendered
 * language tree:
 *
 *   default language              → source records only
 *   non-default, connected mode   → source records only (with connected overlays)
 *   non-default, free mode        → free records of exactly that language only
 *
 * Connected and free records can therefore never render together, a language
 * never sees another language's free records, and free mode never falls back to
 * the source structure.
 */
final class ContentVisibilityPolicy
{
    public function __construct(private readonly CanonicalUrlPolicy $urlPolicy)
    {
    }

    public function isRenderable(
        ContentOwnership $ownership,
        string $activeLanguage,
        bool $isDefaultLanguage,
        ContentTranslationMode $mode,
        int $activeRootPageId = 0,
    ): bool {
        if ($isDefaultLanguage || $mode->isConnected()) {
            // Source structure renders; free records of any language stay hidden.
            return $ownership->isSource();
        }

        if ($ownership->isSource()) {
            // Free mode never renders the source structure, not even when the
            // language owns no content at all.
            return false;
        }

        if (!$this->urlPolicy->languagesEqual($ownership->language, $activeLanguage)) {
            return false;
        }

        // A free record of another root site is never rendered here.
        return 0 === $activeRootPageId || 0 === $ownership->rootPageId || $ownership->rootPageId === $activeRootPageId;
    }

    /**
     * Connected field-state overlays apply to source records of a connected
     * non-default language only. Free records are independent content and never
     * receive an overlay.
     */
    public function usesConnectedOverlay(
        ContentOwnership $ownership,
        bool $isDefaultLanguage,
        ContentTranslationMode $mode,
    ): bool {
        return !$isDefaultLanguage && $mode->isConnected() && $ownership->isSource();
    }
}
