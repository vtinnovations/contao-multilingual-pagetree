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

namespace Vtinnovations\ContaoMultilingualPagetree\Url;

/**
 * A language URL mapping that cannot be stored.
 *
 * It carries the translation key of the reason so the backend can render the
 * message in the editor's language instead of an English-only string.
 */
final class InvalidLanguageUrlException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $reasonKey,
        string $message,
    ) {
        parent::__construct($message);
    }
}
