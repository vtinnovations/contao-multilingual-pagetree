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

namespace Vtinnovations\ContaoMultilingualPagetree\Security;

use Vtinnovations\ContaoMultilingualPagetree\Packaging\ServiceTier;

/**
 * The immutable answer to "what is this installation entitled to?".
 *
 * Everything that gates a capability asks this object, and nothing
 * re-implements period, fallback or host logic of its own. It is shared input,
 * not a switch: each protected operation still performs its own check at its own
 * boundary, so there is no single value whose flip unlocks everything.
 */
final class CapabilityDecision
{
    /**
     * @param list<Capability> $capabilities
     */
    private function __construct(
        public readonly bool $granted,
        public readonly ?ServiceTier $tier,
        public readonly array $capabilities,
        public readonly ?CapabilityDenial $denial,
        public readonly ?string $boundHost,
        public readonly ?int $version,
        public readonly ?int $expiresAt,
        public readonly bool $lifetime,
        public readonly bool $freeFallback,
    ) {
    }

    public static function denied(CapabilityDenial $denial, ?string $boundHost = null, ?int $version = null): self
    {
        return new self(false, null, [], $denial, $boundHost, $version, null, false, false);
    }

    /**
     * @param list<Capability> $capabilities
     */
    public static function granted(
        ServiceTier $tier,
        array $capabilities,
        string $boundHost,
        int $version,
        ?int $expiresAt,
        bool $lifetime,
        bool $freeFallback = false,
    ): self {
        return new self(true, $tier, array_values($capabilities), null, $boundHost, $version, $expiresAt, $lifetime, $freeFallback);
    }

    public function allows(Capability $capability): bool
    {
        return $this->granted && in_array($capability, $this->capabilities, true);
    }

    /** A short, safe category for logs and administrator messages. */
    public function statusLabel(): string
    {
        if (!$this->granted) {
            return $this->denial?->value ?? CapabilityDenial::StateUnusable->value;
        }

        return $this->freeFallback ? 'granted_free_fallback' : 'granted';
    }
}
