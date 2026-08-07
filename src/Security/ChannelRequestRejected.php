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

namespace Vtinnovations\ContaoMultilingualPagetree\Security;

/**
 * An inbound server-to-server request failed authentication or validation.
 *
 * The category is for internal logs only. The public response is generic, so a
 * caller cannot learn which check rejected it and tune the next attempt.
 */
final class ChannelRequestRejected extends \RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct('The inbound request was rejected: '.$category);
    }
}
