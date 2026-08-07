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
 * The one canonical representation of "where does this language live?".
 *
 * It is immutable and already fully resolved: the configured values are kept
 * for the backend, the effective values are what routing, URL generation,
 * canonical metadata, the switcher, collision validation and cache keys use.
 * Nothing recalculates an effective value anywhere else in the bundle.
 */
final class LanguageUrlMapping
{
    /**
     * @param int         $rootId             owning Contao website root
     * @param int         $languageId         tl_inline_language row, 0 for the root's own language
     * @param string      $languageCode       configured language code
     * @param ProtocolMode $configuredProtocol
     * @param string|null $configuredDomain   language-specific hostname, null while inherited
     * @param string      $configuredEntryPoint stored entry point, '' while legacy
     * @param string      $effectiveProtocol  'https' or 'http'
     * @param string|null $effectiveHostname  exact hostname, null when the root configures none
     * @param string      $effectiveEntryPoint '/' or '/de'; never ''
     * @param bool        $isDefaultLanguage  the language of the website root itself
     * @param bool        $isPublished        published language state
     */
    public function __construct(
        public readonly int $rootId,
        public readonly int $languageId,
        public readonly string $languageCode,
        public readonly ProtocolMode $configuredProtocol,
        public readonly ?string $configuredDomain,
        public readonly string $configuredEntryPoint,
        public readonly string $effectiveProtocol,
        public readonly ?string $effectiveHostname,
        public readonly string $effectiveEntryPoint,
        public readonly bool $isDefaultLanguage,
        public readonly bool $isPublished,
        public readonly EntryPointOrigin $entryPointOrigin = EntryPointOrigin::Legacy,
    ) {
    }

    /**
     * True when the language is served from the root of its own domain because
     * it has a domain but no entry point.
     */
    public function hasDomainRootEntryPoint(): bool
    {
        return EntryPointOrigin::DomainRoot === $this->entryPointOrigin;
    }

    /**
     * The path segment this language used to occupy before it was given a
     * domain of its own, or null when it never had one.
     *
     * A request that still carries that segment is stale, not canonical.
     */
    public function legacyPrefix(): ?string
    {
        if (!$this->hasDomainRootEntryPoint()) {
            return null;
        }

        return '/'.ltrim(LanguageUrlMappingSet::normalizeLanguage($this->languageCode), '/');
    }

    /**
     * True while the entry point was never configured, so the record keeps the
     * bundle's previous URL strategy. An explicit `/` is a different state and
     * must never be confused with it.
     */
    public function hasInheritedEntryPoint(): bool
    {
        return EntryPointNormalizer::LEGACY === $this->configuredEntryPoint;
    }

    public function hasExplicitEntryPoint(): bool
    {
        return !$this->hasInheritedEntryPoint();
    }

    public function hasInheritedDomain(): bool
    {
        return null === $this->configuredDomain;
    }

    public function hasInheritedProtocol(): bool
    {
        return $this->configuredProtocol->isInherited();
    }

    /**
     * `https://www.xyz.de`, or null when no hostname is known. A null origin is
     * a valid state: the installation then serves the root under whatever host
     * Contao itself resolved, exactly as before this feature existed.
     */
    public function canonicalOrigin(): ?string
    {
        return null === $this->effectiveHostname ? null : $this->effectiveProtocol.'://'.$this->effectiveHostname;
    }

    /**
     * `https://www.xyz.com/de` - the origin plus the entry point, without a
     * trailing slash. Null when no hostname is known.
     */
    public function canonicalBaseUrl(): ?string
    {
        $origin = $this->canonicalOrigin();

        if (null === $origin) {
            return null;
        }

        return EntryPointNormalizer::ROOT === $this->effectiveEntryPoint ? $origin : $origin.$this->effectiveEntryPoint;
    }

    /**
     * The identity two mappings must not share: exact hostname plus entry
     * point. The protocol is deliberately absent - two languages that differ
     * only by protocol are ambiguous for an incoming request, so the collision
     * validator compares this key and rejects them.
     */
    public function targetKey(): string
    {
        return ($this->effectiveHostname ?? '*').'|'.$this->effectiveEntryPoint;
    }

    /**
     * A cache key that can never be reused across roots, domains, entry points,
     * protocols or publication states.
     */
    public function cacheKey(): string
    {
        return implode('|', [
            'r'.$this->rootId,
            'l'.$this->languageId,
            $this->languageCode,
            $this->effectiveProtocol,
            $this->effectiveHostname ?? '*',
            $this->effectiveEntryPoint,
            $this->entryPointOrigin->value,
            $this->isPublished ? 'on' : 'off',
        ]);
    }

    public function withPublished(bool $published): self
    {
        return new self(
            $this->rootId,
            $this->languageId,
            $this->languageCode,
            $this->configuredProtocol,
            $this->configuredDomain,
            $this->configuredEntryPoint,
            $this->effectiveProtocol,
            $this->effectiveHostname,
            $this->effectiveEntryPoint,
            $this->isDefaultLanguage,
            $published,
            $this->entryPointOrigin,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rootId' => $this->rootId,
            'languageId' => $this->languageId,
            'language' => $this->languageCode,
            'configuredProtocol' => $this->configuredProtocol->value,
            'configuredDomain' => $this->configuredDomain,
            'configuredEntryPoint' => $this->configuredEntryPoint,
            'protocol' => $this->effectiveProtocol,
            'hostname' => $this->effectiveHostname,
            'entryPoint' => $this->effectiveEntryPoint,
            'inheritedEntryPoint' => $this->hasInheritedEntryPoint(),
            'entryPointOrigin' => $this->entryPointOrigin->value,
            'origin' => $this->canonicalOrigin(),
            'baseUrl' => $this->canonicalBaseUrl(),
            'default' => $this->isDefaultLanguage,
            'published' => $this->isPublished,
        ];
    }
}
