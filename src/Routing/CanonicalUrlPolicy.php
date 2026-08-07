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

namespace Vtinnovations\ContaoMultilingualPagetree\Routing;

/**
 * Pure URL policy shared by routing, request handling, metadata and the switcher.
 *
 * Language resolution deliberately does not live here: there is exactly one
 * incoming resolution path, and it is
 * {@see \Vtinnovations\ContaoMultilingualPagetree\Url\IncomingLanguageResolver}.
 */
final class CanonicalUrlPolicy
{
    /**
     * The path of a page in a target language.
     *
     * `$entryPoint` is the effective entry point of the target language, taken
     * from the central language URL mapping: `/` for a language that lives at
     * the domain root and `/de` for a prefixed one. Passing null keeps the
     * previous behaviour - the default language unprefixed, every other
     * language below its own language code - and is what an installation
     * without any configured mapping produces.
     */
    public function buildPagePath(
        string $defaultLanguage,
        string $targetLanguage,
        string $sourceAlias,
        ?string $translatedAlias,
        string $suffix,
        bool $isRoot,
        ?string $entryPoint = null,
    ): string {
        $prefix = $this->prefix($defaultLanguage, $targetLanguage, $entryPoint);

        if ($isRoot) {
            return $prefix === '' ? '/' : $prefix.'/';
        }

        $alias = $translatedAlias !== null ? $translatedAlias : $sourceAlias;

        return $prefix.'/'.$alias.$suffix;
    }

    /**
     * The path prefix a target language contributes, without a trailing slash.
     */
    public function prefix(string $defaultLanguage, string $targetLanguage, ?string $entryPoint = null): string
    {
        if (null !== $entryPoint) {
            return '/' === $entryPoint ? '' : rtrim($entryPoint, '/');
        }

        return $this->languagesEqual($defaultLanguage, $targetLanguage) ? '' : '/'.$targetLanguage;
    }

    public function normalizePath(string $path): string
    {
        $path = '/'.ltrim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function pathsEqual(string $left, string $right): bool
    {
        return $this->normalizePath($left) === $this->normalizePath($right);
    }

    public function normalizeLanguage(?string $language): ?string
    {
        if (!is_string($language) || !preg_match('/^[a-z]{2}(?:[_-][a-z]{2})?$/i', $language)) {
            return null;
        }

        return str_replace('-', '_', strtolower($language));
    }

    public function languagesEqual(string $left, string $right): bool
    {
        return $this->normalizeLanguage($left) === $this->normalizeLanguage($right);
    }
}
