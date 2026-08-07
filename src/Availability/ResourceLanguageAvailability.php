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

/**
 * Availability of the complete current frontend resource in one target
 * language.
 *
 * "Complete" means the page *and* the detail record it addresses: a reader page
 * that is available through page fallback does not make a missing news, event
 * or FAQ translation available.
 */
final class ResourceLanguageAvailability
{
    public const TYPE_PAGE = 'page';

    private function __construct(
        public readonly string $targetLanguage,
        public readonly ResourceAvailabilityStatus $status,
        public readonly string $resourceType,
        /** Canonical, query-free, root-relative path. */
        public readonly ?string $canonicalPath,
        /** Navigable URL including the safe user-facing query parameters. */
        public readonly ?string $url,
        public readonly ?PageAvailabilityResult $pageAvailability,
        public readonly bool $usesPageFallback,
        public readonly bool $publiclyAvailable,
        public readonly bool $previewOnly,
        public readonly ResourceAvailabilityReason $reason,
    ) {
    }

    public static function active(
        string $targetLanguage,
        string $resourceType,
        ?string $canonicalPath,
        ?string $url,
        ?PageAvailabilityResult $pageAvailability,
        bool $usesPageFallback,
        bool $publiclyAvailable,
        bool $previewOnly,
        ResourceAvailabilityReason $reason,
    ): self {
        return new self(
            $targetLanguage,
            ResourceAvailabilityStatus::Active,
            $resourceType,
            $canonicalPath,
            $url,
            $pageAvailability,
            $usesPageFallback,
            $publiclyAvailable,
            $previewOnly,
            $reason,
        );
    }

    public static function available(
        string $targetLanguage,
        string $resourceType,
        string $canonicalPath,
        string $url,
        ?PageAvailabilityResult $pageAvailability,
        bool $usesPageFallback,
    ): self {
        return new self(
            $targetLanguage,
            ResourceAvailabilityStatus::Available,
            $resourceType,
            $canonicalPath,
            $url,
            $pageAvailability,
            $usesPageFallback,
            true,
            false,
            ResourceAvailabilityReason::Available,
        );
    }

    public static function unavailable(
        string $targetLanguage,
        string $resourceType,
        ResourceAvailabilityReason $reason,
        ?PageAvailabilityResult $pageAvailability = null,
    ): self {
        return new self(
            $targetLanguage,
            ResourceAvailabilityStatus::Unavailable,
            $resourceType,
            null,
            null,
            $pageAvailability,
            false,
            false,
            false,
            $reason,
        );
    }

    public function isActive(): bool
    {
        return ResourceAvailabilityStatus::Active === $this->status;
    }

    public function isAvailable(): bool
    {
        return ResourceAvailabilityStatus::Available === $this->status;
    }

    public function isUnavailable(): bool
    {
        return ResourceAvailabilityStatus::Unavailable === $this->status;
    }

    public function isDetailResource(): bool
    {
        return self::TYPE_PAGE !== $this->resourceType;
    }

    /**
     * True when the entry may be rendered as a working navigation link.
     */
    public function isLinkable(): bool
    {
        return null !== $this->url && !$this->isUnavailable();
    }

    /**
     * True when the entry may be advertised publicly, e.g. as an hreflang
     * alternate. Preview-only variants are never advertised.
     */
    public function isPubliclyLinkable(): bool
    {
        return $this->publiclyAvailable && !$this->previewOnly && null !== $this->canonicalPath;
    }
}
