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

use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;

/**
 * The single normaliser for the optional language-specific hostname.
 *
 * It is deliberately thin: {@see CanonicalHost} already is the one hostname
 * canonicaliser of this bundle, and this class only adds the rules the frontend
 * URL field needs on top of it - a scheme, a path, a query string, a fragment,
 * credentials or a port make the value a URL rather than a hostname, and a URL
 * is rejected instead of repaired.
 *
 * What it never does: add or remove `www`, treat a parent domain and a
 * subdomain as equivalent, interpret a wildcard, or resolve anything through
 * DNS. `example.com` and `www.example.com` stay two distinct identities.
 */
final class LanguageDomainNormalizer
{
    public function __construct(private readonly CanonicalHost $hosts)
    {
    }

    /**
     * The canonical hostname of an editor value, or null when the field is
     * empty (which means "inherit the website root domain").
     *
     * @throws InvalidLanguageUrlException when the value is not a plain hostname
     */
    public function normalize(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw $this->invalid('domainInvalid');
        }

        $domain = trim($value);

        if ('' === $domain) {
            return null;
        }

        if (1 === preg_match('~^[a-z][a-z0-9+.-]*://~i', $domain) || str_contains($domain, '//')) {
            throw $this->invalid('domainScheme');
        }

        if (str_contains($domain, '/')) {
            throw $this->invalid('domainPath');
        }

        if (str_contains($domain, '?')) {
            throw $this->invalid('domainQuery');
        }

        if (str_contains($domain, '#')) {
            throw $this->invalid('domainFragment');
        }

        if (str_contains($domain, '@')) {
            throw $this->invalid('domainInvalid');
        }

        // Contao resolves a site root by hostname only, so a port here could
        // never be part of the mapping that decides the root. It is rejected
        // rather than silently dropped, which would store a different host than
        // the editor typed.
        if (str_contains($domain, ':')) {
            throw $this->invalid('domainPort');
        }

        // Exactly one accidental final dot is tolerated; CanonicalHost removes
        // it. "example.com.." stays invalid.
        $canonical = $this->hosts->normalize($domain);

        if (null === $canonical) {
            throw $this->invalid('domainInvalid');
        }

        return $canonical;
    }

    /**
     * Same rules, but a rejected value simply becomes null. Used for reading
     * persisted rows, where a bad row must never break the frontend.
     */
    public function normalizeOrNull(mixed $value): ?string
    {
        try {
            return $this->normalize($value);
        } catch (InvalidLanguageUrlException) {
            return null;
        }
    }

    public function matches(mixed $expected, mixed $actual): bool
    {
        return $this->hosts->matches($expected, $actual);
    }

    private function invalid(string $reasonKey): InvalidLanguageUrlException
    {
        return new InvalidLanguageUrlException($reasonKey, LanguageUrlMessages::text($reasonKey));
    }
}
