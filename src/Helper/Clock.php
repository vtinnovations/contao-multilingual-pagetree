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

namespace Vtinnovations\ContaoMultilingualPagetree\Helper;

/**
 * The one source of wall-clock time for time-sensitive bundle decisions.
 *
 * Everything that compares timestamps - period boundaries, accepted clock skew,
 * retention windows - goes through this port, so tests can assert those
 * boundaries exactly instead of approximating them with sleep().
 */
interface Clock
{
    /** Current UTC Unix timestamp in seconds. */
    public function now(): int;
}
