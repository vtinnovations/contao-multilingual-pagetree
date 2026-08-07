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

namespace Vtinnovations\ContaoMultilingualPagetree\Metadata;

use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlMapping;

/**
 * Builds absolute metadata URLs.
 *
 * The origin comes from the target language's own URL mapping whenever one is
 * known, so a canonical, hreflang or x-default link always carries the protocol
 * and the exact hostname of the language it points at and never leaks the
 * hostname of the language currently being rendered. Only when no mapping and
 * no root domain exist does the current request provide scheme and host.
 */
final class AbsoluteUrlBuilder
{
    public function build(?PageModel $page, string $path, ?Request $request = null, ?LanguageUrlMapping $mapping = null): ?string
    {
        if ('' === $path) {
            return null;
        }

        $origin = $mapping?->canonicalOrigin() ?? $this->origin($page, $request);

        if (null === $origin) {
            return null;
        }

        return $origin.$this->normalisePath($path);
    }

    private function origin(?PageModel $page, ?Request $request): ?string
    {
        $domain = $this->configuredDomain($page);

        if (null !== $domain) {
            $scheme = $this->usesSsl($page, $request) ? 'https' : 'http';

            return $scheme.'://'.$domain;
        }

        if (null === $request) {
            return null;
        }

        return $request->getSchemeAndHttpHost();
    }

    private function configuredDomain(?PageModel $page): ?string
    {
        if (null === $page) {
            return null;
        }

        try {
            $domain = $page->domain ?? null;
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($domain)) {
            return null;
        }

        $domain = trim($domain);

        // Only a plain host (optionally with a port) is accepted.
        if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $domain)) {
            return null;
        }

        return $domain;
    }

    private function usesSsl(?PageModel $page, ?Request $request): bool
    {
        try {
            $useSsl = $page?->rootUseSSL ?? null;
        } catch (\Throwable) {
            $useSsl = null;
        }

        if (null !== $useSsl && '' !== $useSsl) {
            return (bool) $useSsl;
        }

        return null !== $request && $request->isSecure();
    }

    /**
     * Collapses duplicated slashes without touching the query string.
     */
    private function normalisePath(string $path): string
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, null);

        $path = '/'.ltrim((string) $path, '/');
        $path = (string) preg_replace('#/{2,}#', '/', $path);

        return null === $query || '' === $query ? $path : $path.'?'.$query;
    }
}
