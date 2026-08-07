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
 * The request ledger is unavailable.
 *
 * Without it there is no replay protection, so the caller refuses the request
 * instead of processing it unprotected.
 */
final class RequestLedgerException extends \RuntimeException
{
}
