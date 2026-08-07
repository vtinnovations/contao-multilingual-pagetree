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

namespace Vtinnovations\ContaoMultilingualPagetree\Detail;

final class DetailContext
{
    public const NEWS = 'news';
    public const EVENT = 'event';
    public const FAQ = 'faq';

    public function __construct(
        public readonly string $type,
        public readonly int $sourceId,
        public readonly string $sourceAlias,
        public readonly array $routeParameters = [],
    ) {
    }
}
