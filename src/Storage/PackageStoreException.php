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

namespace Vtinnovations\ContaoMultilingualPagetree\Storage;

/**
 * Stored package state could not be written, activated or rolled back.
 *
 * Callers treat this as "the previous state is still in force": a storage
 * failure never erases working state.
 */
final class PackageStoreException extends \RuntimeException
{
}
