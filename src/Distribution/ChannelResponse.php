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

namespace Vtinnovations\ContaoMultilingualPagetree\Distribution;

/**
 * A capped, already fetched response of an outbound channel call.
 */
final class ChannelResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $contentType,
        public readonly string $body,
    ) {
    }

    public function isJson(): bool
    {
        $type = strtolower(trim(explode(';', $this->contentType, 2)[0]));

        return 'application/json' === $type
            || (str_starts_with($type, 'application/') && str_ends_with($type, '+json'));
    }
}
