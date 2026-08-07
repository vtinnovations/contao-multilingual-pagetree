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
 * Every language URL mapping of exactly one Contao website root.
 *
 * The set is always root-scoped: there is no global language-domain map in this
 * bundle, so a mapping of one root can never be consulted for another.
 */
final class LanguageUrlMappingSet
{
    /** @var list<LanguageUrlMapping> */
    private readonly array $mappings;

    /**
     * @param list<LanguageUrlMapping> $mappings
     */
    public function __construct(
        public readonly int $rootId,
        array $mappings,
        private readonly EntryPointNormalizer $entryPoints,
    ) {
        $this->mappings = array_values($mappings);
    }

    /**
     * @return list<LanguageUrlMapping>
     */
    public function all(): array
    {
        return $this->mappings;
    }

    /**
     * @return list<LanguageUrlMapping>
     */
    public function published(): array
    {
        return array_values(array_filter($this->mappings, static fn (LanguageUrlMapping $m): bool => $m->isPublished));
    }

    public function isEmpty(): bool
    {
        return [] === $this->mappings;
    }

    public function forLanguage(string $language): ?LanguageUrlMapping
    {
        $needle = self::normalizeLanguage($language);

        foreach ($this->mappings as $mapping) {
            if (self::normalizeLanguage($mapping->languageCode) === $needle) {
                return $mapping;
            }
        }

        return null;
    }

    public function defaultLanguage(): ?LanguageUrlMapping
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping->isDefaultLanguage) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * True as soon as one language of this root configures its own hostname or
     * an explicit entry point. While it is false the root behaves exactly as it
     * did before this feature existed, and the legacy code paths stay active.
     */
    public function hasCustomMapping(): bool
    {
        foreach ($this->mappings as $mapping) {
            if (!$mapping->hasInheritedDomain() || $mapping->hasExplicitEntryPoint()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every distinct effective hostname of this root, exact and lowercase.
     *
     * @return list<string>
     */
    public function hostnames(): array
    {
        $hosts = [];

        foreach ($this->mappings as $mapping) {
            if (null !== $mapping->effectiveHostname) {
                $hosts[$mapping->effectiveHostname] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * Every explicitly configured entry point of this root, without the leading
     * slash. Contao needs these as URL prefixes so it can derive page aliases
     * from a prefixed request path.
     *
     * @return list<string>
     */
    public function explicitPrefixes(): array
    {
        $prefixes = [];

        foreach ($this->mappings as $mapping) {
            if ($mapping->hasExplicitEntryPoint() && EntryPointNormalizer::ROOT !== $mapping->effectiveEntryPoint) {
                $prefixes[ltrim($mapping->effectiveEntryPoint, '/')] = true;
            }
        }

        return array_keys($prefixes);
    }

    /**
     * The language a request resolves to, using exact hostname matching and
     * complete entry-point boundaries. The longest valid entry point wins.
     *
     * Returns null when the host is not one of this root's hostnames, when no
     * entry point contains the path, or when the configuration is ambiguous -
     * an ambiguous configuration is never guessed.
     */
    public function match(?string $host, string $path): ?LanguageUrlMapping
    {
        $matched = $this->collect($host, $path, true);

        if ([] === $matched && !$this->claimsHost($host)) {
            // Contao itself already decided that this request belongs to this
            // root - through its own site resolution, a proxy or a root without
            // a configured domain. The hostname therefore adds nothing here and
            // only the entry point distinguishes the languages. This pass never
            // associates a hostname with a root; it only picks a language
            // inside a root that was resolved elsewhere.
            $matched = $this->collect($host, $path, false);
        }

        return $this->pickLongest($matched);
    }

    /**
     * @return list<LanguageUrlMapping>
     */
    private function collect(?string $host, string $path, bool $compareHost): array
    {
        $candidates = [];

        foreach ($this->published() as $mapping) {
            if ($compareHost && !$this->hostApplies($mapping, $host)) {
                continue;
            }

            if (!$this->entryPoints->contains($mapping->effectiveEntryPoint, $path)) {
                continue;
            }

            $candidates[] = $mapping;
        }

        return $candidates;
    }

    /**
     * @param list<LanguageUrlMapping> $candidates
     */
    private function pickLongest(array $candidates): ?LanguageUrlMapping
    {
        if ([] === $candidates) {
            return null;
        }

        usort(
            $candidates,
            fn (LanguageUrlMapping $a, LanguageUrlMapping $b): int => $this->entryPoints->depth($b->effectiveEntryPoint) <=> $this->entryPoints->depth($a->effectiveEntryPoint),
        );

        $best = $candidates[0];
        $depth = $this->entryPoints->depth($best->effectiveEntryPoint);

        foreach (array_slice($candidates, 1) as $candidate) {
            // Two languages that claim the same depth on the same host are a
            // configuration error. Guessing one of them would silently serve
            // the wrong language, so nothing is resolved at all.
            if ($this->entryPoints->depth($candidate->effectiveEntryPoint) === $depth
                && $candidate->effectiveEntryPoint === $best->effectiveEntryPoint
            ) {
                return null;
            }
        }

        return $best;
    }

    /**
     * True when this root claims the exact hostname through a persisted
     * mapping. Never a suffix, wildcard or parent-domain match.
     */
    public function claimsHost(?string $host): bool
    {
        if (null === $host || '' === $host) {
            return false;
        }

        return in_array(strtolower($host), $this->hostnames(), true);
    }

    /**
     * A cache key covering every mapping of this root, so a changed protocol,
     * domain, entry point or publication state can never reuse an older entry.
     */
    public function cacheKey(): string
    {
        $parts = ['root'.$this->rootId];

        foreach ($this->mappings as $mapping) {
            $parts[] = $mapping->cacheKey();
        }

        return implode(';', $parts);
    }

    private function hostApplies(LanguageUrlMapping $mapping, ?string $host): bool
    {
        if (!$this->hasCustomMapping()) {
            // Nothing in this root configures a hostname or an entry point, so
            // the host was never part of the decision before this feature
            // existed and must not become part of it now.
            return true;
        }

        if (null === $mapping->effectiveHostname) {
            // The root configures no domain at all. Contao itself decided that
            // this request belongs to this root, so every language of the root
            // applies and only the entry point distinguishes them.
            return true;
        }

        if (null === $host || '' === $host) {
            return false;
        }

        return hash_equals($mapping->effectiveHostname, strtolower($host));
    }

    public static function normalizeLanguage(string $language): string
    {
        return str_replace('-', '_', strtolower(trim($language)));
    }
}
