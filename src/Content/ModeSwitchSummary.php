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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

/**
 * What a mode change would make inactive.
 *
 * Switching modes never deletes anything: the summary exists so an editor can
 * confirm which stored content stops rendering.
 */
final class ModeSwitchSummary
{
    public function __construct(
        public readonly string $language,
        public readonly int $rootPageId,
        public readonly ContentTranslationMode $current,
        public readonly ContentTranslationMode $requested,
        public readonly int $connectedArticleTranslations,
        public readonly int $connectedContentTranslations,
        public readonly int $freeArticles,
        public readonly int $freeContentElements,
    ) {
    }

    public function isChange(): bool
    {
        return $this->current !== $this->requested;
    }

    public function connectedRecords(): int
    {
        return $this->connectedArticleTranslations + $this->connectedContentTranslations;
    }

    public function freeRecords(): int
    {
        return $this->freeArticles + $this->freeContentElements;
    }

    /**
     * Records that stop rendering when the requested mode becomes active. No
     * record is ever deleted.
     */
    public function recordsBecomingInactive(): int
    {
        if (!$this->isChange()) {
            return 0;
        }

        return $this->requested->isFree() ? $this->connectedRecords() : $this->freeRecords();
    }

    public function requiresConfirmation(): bool
    {
        return $this->isChange() && $this->recordsBecomingInactive() > 0;
    }
}
