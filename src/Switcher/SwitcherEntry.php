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

namespace Vtinnovations\ContaoMultilingualPagetree\Switcher;

use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityStatus;

/**
 * One language entry of the switcher, resolved to exactly one presentation
 * state: active, available or unavailable.
 */
final class SwitcherEntry
{
    public function __construct(
        public readonly string $language,
        public readonly string $label,
        public readonly string $flag,
        public readonly string $hreflang,
        public readonly ResourceAvailabilityStatus $status,
        public readonly ?string $href,
        public readonly bool $usesFallback,
        public readonly bool $previewOnly,
        public readonly string $reason,
    ) {
    }

    public function isActive(): bool
    {
        return ResourceAvailabilityStatus::Active === $this->status;
    }

    public function isAvailable(): bool
    {
        return ResourceAvailabilityStatus::Available === $this->status && null !== $this->href;
    }

    public function isUnavailable(): bool
    {
        return !$this->isActive() && !$this->isAvailable();
    }

    /**
     * Plain data for the Twig template. The template only renders states; it
     * never decides availability itself.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'label' => $this->label,
            'flag' => $this->flag,
            'hreflang' => $this->hreflang,
            'status' => $this->isUnavailable() ? ResourceAvailabilityStatus::Unavailable->value : $this->status->value,
            'href' => $this->isUnavailable() ? null : $this->href,
            'active' => $this->isActive(),
            'available' => $this->isAvailable(),
            'unavailable' => $this->isUnavailable(),
            'fallback' => $this->usesFallback,
            'previewOnly' => $this->previewOnly,
            'reason' => $this->reason,
        ];
    }
}
