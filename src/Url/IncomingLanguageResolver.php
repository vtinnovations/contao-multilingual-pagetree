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

use Symfony\Component\HttpFoundation\Request;

/**
 * The one place that decides which language an incoming frontend request is in.
 *
 * The decision order is fixed and deterministic:
 *
 *  1. the matched route - the route provider builds every language route from
 *     the same mappings, so a matched route is already the authoritative answer;
 *  2. the persisted mapping of the owning website root: exact hostname, then
 *     the longest entry point that contains the request path on a complete
 *     segment boundary;
 *  3. the default language of the website root.
 *
 * A browser header, a cookie, a session or a posted value is never consulted.
 * An ambiguous configuration resolves to nothing in step 2 and therefore falls
 * through to the default language instead of being guessed.
 */
final class IncomingLanguageResolver
{
    public const ROUTE_ATTRIBUTE = '_contao_multilingual_pagetree';

    public function __construct(private readonly LanguageUrlResolver $urls)
    {
    }

    /**
     * @param list<string> $enabledLanguages
     */
    public function resolve(?Request $request, int $rootId, string $defaultLanguage, array $enabledLanguages): string
    {
        $enabled = [];

        foreach ($enabledLanguages as $language) {
            $key = LanguageUrlMappingSet::normalizeLanguage((string) $language);

            if ('' !== $key) {
                $enabled[$key] = (string) $language;
            }
        }

        if (null === $request) {
            return $defaultLanguage;
        }

        $routeLanguage = $request->attributes->get(self::ROUTE_ATTRIBUTE);

        if (is_string($routeLanguage)) {
            $key = LanguageUrlMappingSet::normalizeLanguage($routeLanguage);

            if (isset($enabled[$key])) {
                return $enabled[$key];
            }
        }

        $mapping = $this->urls->resolveRequest($rootId, $request);

        if (null !== $mapping) {
            $key = LanguageUrlMappingSet::normalizeLanguage($mapping->languageCode);

            if (isset($enabled[$key])) {
                return $enabled[$key];
            }
        }

        return $defaultLanguage;
    }

    /**
     * The mapping the current request resolved to, for the canonical redirect
     * and for URL generation. Null when the request does not match any
     * persisted mapping of this root.
     */
    public function mapping(?Request $request, int $rootId): ?LanguageUrlMapping
    {
        return null === $request ? null : $this->urls->resolveRequest($rootId, $request);
    }
}
