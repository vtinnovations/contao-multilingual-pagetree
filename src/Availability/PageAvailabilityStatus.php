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
 * Outcome of a page-availability decision for one target language.
 */
enum PageAvailabilityStatus: string
{
    /** An available page translation provides the overlay and the alias. */
    case Translated = 'translated';

    /** No available translation: the source page provides content and alias. */
    case Fallback = 'fallback';

    /** The page must not be reachable in the target language. */
    case Unavailable = 'unavailable';
}
