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

use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityReason;

/**
 * Structured outcome of resolving a detail record in a target language.
 *
 * The canonical path is query free and root relative; the URL is the navigable
 * variant that keeps the safe user-facing query parameters of point 3.
 */
final class DetailTargetResult
{
    private function __construct(
        public readonly bool $available,
        public readonly ?string $path,
        public readonly ?string $url,
        public readonly ?string $alias,
        public readonly ResourceAvailabilityReason $reason,
    ) {
    }

    public static function available(string $path, string $url, string $alias): self
    {
        return new self(true, $path, $url, $alias, ResourceAvailabilityReason::Available);
    }

    public static function unavailable(ResourceAvailabilityReason $reason): self
    {
        return new self(false, null, null, null, $reason);
    }

    public function isDetailResource(): bool
    {
        return ResourceAvailabilityReason::NotADetailResource !== $this->reason;
    }
}
