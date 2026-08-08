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
 * The single normaliser and matcher for the optional language entry point.
 *
 * Stored forms - and only these:
 *
 *  - `''`   the field was never configured. The bundle keeps its previous URL
 *           strategy for that record. This is *not* the same as `/`.
 *  - `/`    the language explicitly lives at the root of its effective domain.
 *  - `/de`  the language explicitly lives below that path prefix.
 *
 * Matching is always on complete path-segment boundaries, so `/de` matches
 * `/de`, `/de/` and `/de/about` but never `/demo` or `/development`.
 */
final class EntryPointNormalizer
{
    /** The stored value that preserves the previous/default URL strategy. */
    public const LEGACY = '';

    /** The stored value that forces the effective domain root. */
    public const ROOT = '/';

    private const MAX_LENGTH = 191;

    /**
     * The canonical stored form of an editor value.
     *
     * `de` becomes `/de`, `/de/` becomes `/de`, `/` stays `/` and an empty
     * field stays empty. Nothing else is repaired.
     *
     * @throws InvalidLanguageUrlException when the value is not a usable path prefix
     */
    public function normalize(mixed $value): string
    {
        if (null === $value) {
            return self::LEGACY;
        }

        if (!is_string($value)) {
            throw $this->invalid('entryPointInvalid');
        }

        // Inspect the raw scalar before trimming: otherwise a trailing newline
        // or tab would be silently converted into an apparently valid path.
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw $this->invalid('entryPointControl');
        }

        $path = trim($value);

        if ('' === $path) {
            return self::LEGACY;
        }

        if (1 === preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
            throw $this->invalid('entryPointUrl');
        }

        if (str_contains($path, '?')) {
            throw $this->invalid('entryPointQuery');
        }

        if (str_contains($path, '#')) {
            throw $this->invalid('entryPointFragment');
        }

        if (str_contains($path, '\\') || str_contains($path, '@')) {
            throw $this->invalid('entryPointInvalid');
        }

        // Encoded separators/dots and literal dot segments are traversal before
        // they are anything else. Classify them before the hostname heuristic
        // so a value such as "../de" receives the precise security error.
        $lower = strtolower($path);

        if (str_contains($lower, '%2f') || str_contains($lower, '%5c') || str_contains($lower, '%2e')) {
            throw $this->invalid('entryPointTraversal');
        }

        foreach (explode('/', $path) as $rawSegment) {
            if ('.' === $rawSegment || '..' === $rawSegment) {
                throw $this->invalid('entryPointTraversal');
            }
        }

        // "//host/path" and "www.example.com/de" are host forms, not paths.
        if (str_starts_with($path, '//')) {
            throw $this->invalid('entryPointHost');
        }

        // A bare value may omit the leading slash ("en"), but a dotted first
        // component in that form is a hostname, not an entry-point segment.
        if (!str_starts_with($path, '/') && 1 === preg_match('/^[^\/]*\./', $path)) {
            throw $this->invalid('entryPointHost');
        }

        $path = '/'.ltrim($path, '/');

        if ('/' === $path) {
            return self::ROOT;
        }

        // One trailing slash is presentation noise. More than one still leaves
        // an empty segment and is rejected by the segment loop below.
        if (str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        $segments = [];

        foreach (explode('/', substr($path, 1)) as $segment) {
            if ('' === $segment) {
                // A repeated slash carries no segment and cannot be resolved
                // deterministically against a request path.
                throw $this->invalid('entryPointSlashes');
            }

            if (1 === preg_match('/^\.+$/', $segment)) {
                throw $this->invalid('entryPointTraversal');
            }

            // Use a delimiter that is not itself an allowed path character.
            // With "~" as the delimiter, the literal "~" in this character
            // class terminated PHP's pattern early and every segment failed.
            if (1 !== preg_match('#^[A-Za-z0-9._~!$&\'()*+,;=:@-]+$#', $segment)) {
                throw $this->invalid('entryPointInvalid');
            }

            $segments[] = $segment;
        }

        $normalized = '/'.implode('/', $segments);

        if (strlen($normalized) > self::MAX_LENGTH) {
            throw $this->invalid('entryPointInvalid');
        }

        return $normalized;
    }

    /**
     * Same rules, but a rejected value falls back to the legacy strategy. Used
     * for reading persisted rows, where a bad row must never break routing.
     */
    public function normalizeOrLegacy(mixed $value): string
    {
        try {
            return $this->normalize($value);
        } catch (InvalidLanguageUrlException) {
            return self::LEGACY;
        }
    }

    public function isLegacy(string $entryPoint): bool
    {
        return self::LEGACY === $entryPoint;
    }

    public function isRoot(string $entryPoint): bool
    {
        return self::ROOT === $entryPoint;
    }

    /**
     * True when a request path lies inside an entry point, on a complete
     * segment boundary. `/` contains every path.
     */
    public function contains(string $entryPoint, string $path): bool
    {
        if ('' === $entryPoint || self::ROOT === $entryPoint) {
            return self::ROOT === $entryPoint;
        }

        $path = '/'.ltrim($path, '/');

        return $path === $entryPoint || str_starts_with($path, $entryPoint.'/');
    }

    /**
     * The request path with the entry point removed, always starting with `/`.
     * A path outside the entry point is returned unchanged.
     */
    public function strip(string $entryPoint, string $path): string
    {
        if (!$this->contains($entryPoint, $path) || self::ROOT === $entryPoint) {
            return '/'.ltrim($path, '/');
        }

        $remainder = substr('/'.ltrim($path, '/'), strlen($entryPoint));

        return '' === $remainder ? '/' : '/'.ltrim($remainder, '/');
    }

    /**
     * The entry point placed in front of a page path exactly once.
     */
    public function prepend(string $entryPoint, string $path): string
    {
        $path = '/'.ltrim($path, '/');

        if ('' === $entryPoint || self::ROOT === $entryPoint) {
            return $path;
        }

        if ($this->contains($entryPoint, $path)) {
            return $path;
        }

        return '/' === $path ? $entryPoint.'/' : $entryPoint.$path;
    }

    /** The number of path segments, used to order longest-match-first. */
    public function depth(string $entryPoint): int
    {
        if ('' === $entryPoint || self::ROOT === $entryPoint) {
            return 0;
        }

        return count(explode('/', trim($entryPoint, '/')));
    }

    private function invalid(string $reasonKey): InvalidLanguageUrlException
    {
        return new InvalidLanguageUrlException($reasonKey, LanguageUrlMessages::text($reasonKey));
    }
}
