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
 * Presentation state of one configured site language for the resource that is
 * currently being rendered.
 */
enum ResourceAvailabilityStatus: string
{
    /** The URL-driven language of the current request. */
    case Active = 'active';

    /** The complete current resource has a valid canonical URL in this language. */
    case Available = 'available';

    /** The current resource has no valid frontend URL in this language. */
    case Unavailable = 'unavailable';
}
