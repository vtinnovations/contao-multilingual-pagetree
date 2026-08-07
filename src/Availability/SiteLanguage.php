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

use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentFallbackMode;

/**
 * One configured language of one Contao root site.
 */
final class SiteLanguage
{
    public function __construct(
        public readonly string $language,
        public readonly string $label,
        public readonly string $flag,
        public readonly bool $isDefault,
        public readonly PageAvailabilityMode $mode,
        public readonly ContentTranslationMode $contentMode = ContentTranslationMode::Connected,
        /** The tl_inline_language row, so callers can address the exact record. */
        public readonly int $id = 0,
        public readonly ContentFallbackMode $contentFallbackMode = ContentFallbackMode::Fallback,
    ) {
    }

    /**
     * Backwards compatible array shape used by the language switcher and the
     * metadata listeners.
     *
     * @return array{language: string, label: string, flag: string, fallback: bool, mode: string, contentMode: string, contentFallbackMode: string, id: int}
     */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'label' => $this->label,
            'flag' => $this->flag,
            'fallback' => $this->isDefault,
            'mode' => $this->mode->value,
            'contentMode' => $this->contentMode->value,
            'contentFallbackMode' => $this->contentFallbackMode->value,
            'id' => $this->id,
        ];
    }
}
