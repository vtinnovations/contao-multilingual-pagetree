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

namespace Vtinnovations\ContaoMultilingualPagetree\Translation;

final class TranslationFieldRegistration
{
    public function __construct(
        public readonly string $sourceTable,
        public readonly string $field,
        public readonly string $valueType = 'string',
        public readonly ?string $contentType = null,
    ) {
    }
}
