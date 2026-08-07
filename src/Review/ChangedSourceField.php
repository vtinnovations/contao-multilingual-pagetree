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

namespace Vtinnovations\ContaoMultilingualPagetree\Review;

/**
 * One translatable source field that changed since the last review.
 *
 * Both previews are safe plain text: rich text is stripped, arrays are
 * summarised and nothing is ever rendered as markup.
 */
final class ChangedSourceField
{
    public function __construct(
        public readonly string $field,
        public readonly string $reviewedPreview,
        public readonly string $currentPreview,
    ) {
    }
}
