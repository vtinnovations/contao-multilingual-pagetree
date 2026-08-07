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

use Contao\PageModel;

/**
 * Structured decision of {@see PageAvailabilityResolver}.
 *
 * The result carries everything routing, request handling, metadata and the
 * language switcher need, so no consumer has to repeat the decision.
 */
final class PageAvailabilityResult
{
    private function __construct(
        public readonly PageAvailabilityStatus $status,
        public readonly PageModel $sourcePage,
        public readonly ?object $translation,
        public readonly string $targetLanguage,
        public readonly string $defaultLanguage,
        public readonly PageAvailabilityMode $mode,
        public readonly PageAvailabilityReason $reason,
        public readonly ?string $effectiveAlias,
        public readonly string $sourceAlias,
        public readonly bool $isRootPage,
        public readonly bool $isDefaultLanguage,
    ) {
    }

    public static function defaultLanguage(
        PageModel $sourcePage,
        string $targetLanguage,
        string $defaultLanguage,
        string $sourceAlias,
        bool $isRootPage,
    ): self {
        return new self(
            PageAvailabilityStatus::Translated,
            $sourcePage,
            null,
            $targetLanguage,
            $defaultLanguage,
            PageAvailabilityMode::Fallback,
            PageAvailabilityReason::Available,
            $sourceAlias,
            $sourceAlias,
            $isRootPage,
            true,
        );
    }

    public static function translated(
        PageModel $sourcePage,
        object $translation,
        string $targetLanguage,
        string $defaultLanguage,
        PageAvailabilityMode $mode,
        string $effectiveAlias,
        string $sourceAlias,
        bool $isRootPage,
    ): self {
        return new self(
            PageAvailabilityStatus::Translated,
            $sourcePage,
            $translation,
            $targetLanguage,
            $defaultLanguage,
            $mode,
            PageAvailabilityReason::Available,
            $effectiveAlias,
            $sourceAlias,
            $isRootPage,
            false,
        );
    }

    public static function fallback(
        PageModel $sourcePage,
        ?object $translation,
        string $targetLanguage,
        string $defaultLanguage,
        PageAvailabilityReason $reason,
        string $sourceAlias,
        bool $isRootPage,
    ): self {
        return new self(
            PageAvailabilityStatus::Fallback,
            $sourcePage,
            $translation,
            $targetLanguage,
            $defaultLanguage,
            PageAvailabilityMode::Fallback,
            $reason,
            $sourceAlias,
            $sourceAlias,
            $isRootPage,
            false,
        );
    }

    public static function unavailable(
        PageModel $sourcePage,
        ?object $translation,
        string $targetLanguage,
        string $defaultLanguage,
        PageAvailabilityMode $mode,
        PageAvailabilityReason $reason,
        string $sourceAlias = '',
        bool $isRootPage = false,
    ): self {
        return new self(
            PageAvailabilityStatus::Unavailable,
            $sourcePage,
            $translation,
            $targetLanguage,
            $defaultLanguage,
            $mode,
            $reason,
            null,
            $sourceAlias,
            $isRootPage,
            false,
        );
    }

    public function isTranslated(): bool
    {
        return PageAvailabilityStatus::Translated === $this->status;
    }

    public function isFallback(): bool
    {
        return PageAvailabilityStatus::Fallback === $this->status;
    }

    public function isUnavailable(): bool
    {
        return PageAvailabilityStatus::Unavailable === $this->status;
    }

    public function isAvailable(): bool
    {
        return !$this->isUnavailable();
    }

    /**
     * True when the source page provides the rendered content because no
     * available translation exists.
     */
    public function usesFallbackContent(): bool
    {
        return $this->isFallback();
    }

    /**
     * The page model Contao renders. Fallback pages deliberately render the
     * unmodified source page; no synthetic translation record is created.
     */
    public function effectivePage(): PageModel
    {
        return $this->sourcePage;
    }

    /**
     * The translation record that may be overlaid before rendering, or null
     * when the source record is rendered as is.
     */
    public function overlayTranslation(): ?object
    {
        return $this->isTranslated() ? $this->translation : null;
    }
}
