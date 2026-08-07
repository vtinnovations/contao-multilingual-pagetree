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

use Contao\CoreBundle\Routing\Page\PageRoute;
use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Cmf\Component\Routing\RouteProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlMapping;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

/**
 * Adds the language routes of Contao Multilingual Pagetree to Contao's own page routes.
 *
 * Which non-default language route exists is decided exclusively by the central
 * page-availability service:
 *
 *  - strict:   a route exists only for an available published translation and
 *              always uses the translated alias
 *  - fallback: an available translation uses the translated alias, otherwise a
 *              route with the source alias is generated below the language
 *              prefix and marked as fallback content
 *
 * The unprefixed default-language route stays Contao's own route, and the
 * prefixed default-language path remains redirect-only (point 1).
 */
class MultilingualPagetreeRouteProviderDecorator implements RouteProviderInterface
{
    private const KIND_CANONICAL = 'canonical';
    private const KIND_REDIRECT = 'redirect';

    public function __construct(
        private readonly RouteProviderInterface $inner,
        private readonly LanguageHelper $languageHelper,
        private readonly CanonicalUrlPolicy $urlPolicy,
        private readonly PageAvailabilityResolver $availabilityResolver,
        private readonly ?LanguageUrlResolver $urlResolver = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getRouteCollectionForRequest(Request $request): RouteCollection
    {
        $collection = $this->inner->getRouteCollectionForRequest($request);
        $this->bootstrapLanguageDomainRoot($request, $collection);
        $requestPath = $request->getPathInfo();

        /** @var list<array<string, mixed>> $plans */
        $plans = [];

        /** @var array<string, true> $canonicalPaths */
        $canonicalPaths = [];

        foreach ($collection as $name => $route) {
            $pageModel = $route->getDefault('pageModel');
            if (!$pageModel instanceof PageModel || str_starts_with((string) $name, 'contao_multilingual_pagetree.')) {
                continue;
            }
            if (str_ends_with((string) $name, '.fallback')) {
                continue;
            }

            try {
                $pageModel->loadDetails();
            } catch (\Throwable) {
                continue;
            }

            $rootPageId = $pageModel->type === 'root' ? (int) $pageModel->id : (int) $pageModel->rootId;
            if ($rootPageId <= 0) {
                continue;
            }

            $defaultLanguage = $this->languageHelper->getDefaultLanguage($rootPageId);
            $urlSuffix = $this->getUrlSuffix($route);
            $isRoot = $this->isRootRoute((string) $name, $pageModel);

            // The source page decides whether any language route may exist at all.
            $sourceResult = $this->availabilityResolver->resolve($pageModel, $defaultLanguage);
            if ($sourceResult->isUnavailable()) {
                continue;
            }

            // Native Contao routes are the sole content routes for the site
            // default language as long as that language keeps the domain and
            // entry point of its website root.
            $route->setDefault('_locale', $defaultLanguage);
            $route->setDefault('_contao_multilingual_pagetree', $defaultLanguage);
            $canonicalPaths[$this->targetKey($route->getHost(), $route->getPath())] = true;

            $defaultMapping = $this->mapping($rootPageId, $defaultLanguage);

            // A default language that was moved to its own domain or below an
            // explicit entry point needs a route of its own; Contao's route
            // still carries the root's host and unprefixed path.
            if ($this->needsOwnRoute($defaultMapping, $route)) {
                $defaultPath = $this->availabilityResolver->canonicalPath($sourceResult, $urlSuffix);

                if (null !== $defaultPath) {
                    $canonicalPaths[$this->targetKey($defaultMapping?->effectiveHostname, $defaultPath)] = true;
                    $plans[] = [
                        'kind' => self::KIND_CANONICAL,
                        'name' => $this->localizedRouteName((int) $pageModel->id, $defaultLanguage, $isRoot),
                        'origin' => (string) $name,
                        'route' => $route,
                        'page' => $pageModel,
                        'language' => $defaultLanguage,
                        'path' => $defaultPath,
                        'suffix' => $urlSuffix,
                        'fallback' => false,
                        'host' => $defaultMapping?->effectiveHostname,
                    ];
                }
            }

            foreach ($this->languageHelper->getAvailableLanguages($rootPageId) as $configuration) {
                $language = (string) $configuration['language'];
                if ($this->urlPolicy->languagesEqual($language, $defaultLanguage)) {
                    continue;
                }

                $mapping = $this->mapping($rootPageId, $language);
                $host = $mapping?->effectiveHostname;

                $result = $this->availabilityResolver->resolve($pageModel, $language);
                if ($result->isUnavailable()) {
                    // Strict mode without an available translation: no route.
                    continue;
                }

                $path = $this->availabilityResolver->canonicalPath($result, $urlSuffix);
                if ($path === null) {
                    continue;
                }

                $canonicalPaths[$this->targetKey($host, $path)] = true;
                $plans[] = [
                    'kind' => self::KIND_CANONICAL,
                    'name' => $this->localizedRouteName((int) $pageModel->id, $language, $isRoot),
                    'origin' => (string) $name,
                    'route' => $route,
                    'page' => $pageModel,
                    'language' => $language,
                    'path' => $path,
                    'suffix' => $urlSuffix,
                    'fallback' => $result->usesFallbackContent(),
                    'host' => $host,
                ];

                // A custom-domain language that moved from the inherited
                // language-code path to the domain root keeps that stale path
                // matchable only for the canonical redirect listener.
                if ($mapping?->hasDomainRootEntryPoint() && null !== $mapping->legacyPrefix()) {
                    $stalePath = $isRoot
                        ? $mapping->legacyPrefix()
                        : rtrim($mapping->legacyPrefix(), '/').'/'.ltrim($path, '/');

                    if (!$this->urlPolicy->pathsEqual($stalePath, $path)) {
                        $plans[] = [
                            'kind' => self::KIND_REDIRECT,
                            'name' => sprintf('contao_multilingual_pagetree.redirect.%d.%s.domain_root', (int) $pageModel->id, $language),
                            'origin' => (string) $name,
                            'route' => $route,
                            'page' => $pageModel,
                            'language' => $language,
                            'path' => $stalePath,
                            'suffix' => $urlSuffix,
                            'fallback' => false,
                            'host' => $host,
                        ];
                    }
                }

                // A translated alias makes the former fallback URL obsolete; it
                // stays matchable only to redirect to the canonical alias.
                if ($result->isTranslated()) {
                    $obsoletePath = $this->availabilityResolver->fallbackPath($result, $urlSuffix);

                    if (null !== $obsoletePath && !$this->urlPolicy->pathsEqual($obsoletePath, $path)) {
                        $plans[] = [
                            'kind' => self::KIND_REDIRECT,
                            'name' => sprintf('contao_multilingual_pagetree.redirect.%d.%s.obsolete', (int) $pageModel->id, $language),
                            'origin' => (string) $name,
                            'route' => $route,
                            'page' => $pageModel,
                            'language' => $language,
                            'path' => $obsoletePath,
                            'suffix' => $urlSuffix,
                            'fallback' => false,
                            'host' => $host,
                        ];
                    }
                }
            }

            // The old default prefix remains matchable only to issue a permanent
            // redirect. It is skipped as soon as an explicit entry point owns
            // that prefix, so a configured mapping is never shadowed.
            if (!$this->prefixIsClaimed($rootPageId, $defaultLanguage)) {
                $plans[] = [
                    'kind' => self::KIND_REDIRECT,
                    'name' => sprintf('contao_multilingual_pagetree.redirect.%d.%s%s', (int) $pageModel->id, $defaultLanguage, $isRoot ? '.root' : ''),
                    'origin' => (string) $name,
                    'route' => $route,
                    'page' => $pageModel,
                    'language' => $defaultLanguage,
                    'path' => $this->buildPrefixedDefaultPath($sourceResult->sourceAlias, $defaultLanguage, $urlSuffix, $isRoot),
                    'suffix' => $urlSuffix,
                    'fallback' => false,
                    'host' => $defaultMapping?->effectiveHostname,
                ];
            }
        }

        $additionalRoutes = [];

        foreach ($plans as $plan) {
            $path = (string) $plan['path'];
            $host = is_string($plan['host'] ?? null) ? (string) $plan['host'] : null;

            // A redirect must never shadow a page that legitimately owns the
            // very same host and path.
            if (self::KIND_REDIRECT === $plan['kind'] && isset($canonicalPaths[$this->targetKey($host, $path)])) {
                continue;
            }

            $localizedRoute = $this->createLocalizedRoute($plan['route'], $path, $plan['page'], (string) $plan['language'], $host);

            if (self::KIND_REDIRECT === $plan['kind']) {
                $localizedRoute->setDefault('_contao_multilingual_pagetree_canonical_redirect', true);
            } elseif (true === $plan['fallback']) {
                // The request resolver must know that source content is intended.
                $localizedRoute->setDefault('_contao_multilingual_pagetree_fallback', true);
            }

            $additionalRoutes[(string) $plan['name']] = $localizedRoute;
            $additionalRoutes[$plan['name'].'.params'] = $this->createParametersRoute($localizedRoute, (string) $plan['suffix']);

            if ($this->urlPolicy->pathsEqual($requestPath, $path) && $this->hostMatchesRequest($host, $request)) {
                $collection->add((string) $plan['origin'], clone $localizedRoute);
            }
        }

        foreach ($additionalRoutes as $name => $route) {
            $collection->add($name, $route);
        }

        return $collection;
    }

    /**
     * Contao 5.3 handles `/` in RouteProvider::findRootPages(), which considers
     * tl_page.dns only. A hostname persisted on tl_inline_language therefore
     * gives this decorator an empty collection. Bootstrap the owning root's
     * native route for that one exact, published language hostname; the normal
     * plan builder then replaces it with the canonical language route.
     */
    private function bootstrapLanguageDomainRoot(Request $request, RouteCollection $collection): void
    {
        if (null === $this->urlResolver || 0 !== count($collection)) {
            return;
        }

        try {
            $host = $request->getHost();
        } catch (\Throwable) {
            return;
        }

        $rootPageId = $this->urlResolver->rootForLanguageHost($host);

        if (null === $rootPageId) {
            return;
        }

        $route = null;
        $routeName = 'tl_page.'.$rootPageId.'.root';

        try {
            $route = $this->inner->getRouteByName($routeName);
        } catch (RouteNotFoundException) {
            $routeName = 'tl_page.'.$rootPageId;

            try {
                $route = $this->inner->getRouteByName($routeName);
            } catch (RouteNotFoundException) {
                return;
            }
        }

        $page = $route->getDefault('pageModel');

        if (!$page instanceof PageModel) {
            return;
        }

        $mapping = $this->urlResolver->resolveRequest($rootPageId, $request);

        if (null === $mapping || !$mapping->hasDomainRootEntryPoint()) {
            return;
        }

        $collection->add($routeName, $route);

        $this->logger?->debug('Contao Multilingual Pagetree: bootstrapped the active route provider for a language-domain root request.', [
            'resolver' => $this->urlResolver::class,
            'rootId' => $rootPageId,
            'languageId' => $mapping->languageId,
            'language' => $mapping->languageCode,
            'configuredDomain' => $mapping->configuredDomain,
            'customDomain' => !$mapping->hasInheritedDomain(),
            'configuredEntryPoint' => $mapping->configuredEntryPoint,
            'effectiveEntryPoint' => $mapping->effectiveEntryPoint,
            'entryPointOrigin' => $mapping->entryPointOrigin->value,
            'host' => $host,
            'path' => $request->getPathInfo(),
        ]);
    }

    public function getRouteByName($name): Route
    {
        $name = (string) $name;
        if (!preg_match('/^tl_page\.([1-9]\d*)\.([a-z]{2}(?:[_-][a-z]{2})?)(\.root)?$/i', $name, $matches)) {
            return $this->inner->getRouteByName($name);
        }

        $pageId = (int) $matches[1];
        $language = $matches[2];
        $isRoot = ($matches[3] ?? '') === '.root';
        $baseRoute = $this->inner->getRouteByName('tl_page.'.$pageId.($isRoot ? '.root' : ''));
        $pageModel = $baseRoute->getDefault('pageModel');
        if (!$pageModel instanceof PageModel) {
            throw new RouteNotFoundException(sprintf('Page route "%s" has no page model.', $name));
        }

        $pageModel->loadDetails();
        $rootPageId = $pageModel->type === 'root' ? (int) $pageModel->id : (int) $pageModel->rootId;
        if (!$this->languageHelper->isLanguageEnabled($language, $rootPageId)
            || $this->languageHelper->isDefaultLanguage($language, $rootPageId)) {
            throw new RouteNotFoundException(sprintf('No non-default language route named "%s" exists.', $name));
        }

        $result = $this->availabilityResolver->resolve($pageModel, $language);
        $path = $this->availabilityResolver->canonicalPath($result, $this->getUrlSuffix($baseRoute));
        if (null === $path) {
            throw new RouteNotFoundException(sprintf('The page of route "%s" is not available in this language.', $name));
        }

        $route = $this->createLocalizedRoute($baseRoute, $path, $pageModel, $language, $this->mapping($rootPageId, $language)?->effectiveHostname);
        if ($result->usesFallbackContent()) {
            $route->setDefault('_contao_multilingual_pagetree_fallback', true);
        }

        return $route;
    }

    public function getRoutesByNames($names = null): iterable
    {
        if (is_array($names)) {
            $routes = [];
            foreach ($names as $name) {
                try {
                    $routes[(string) $name] = $this->getRouteByName($name);
                } catch (RouteNotFoundException) {
                }
            }

            return $routes;
        }

        return $this->inner->getRoutesByNames($names);
    }

    /**
     * @param string|null $host the target language's exact hostname, or null to
     *                          keep the host requirement of the source route
     */
    private function createLocalizedRoute(Route $source, string $path, PageModel $pageModel, string $language, ?string $host = null): Route
    {
        $defaults = $source->getDefaults();
        $defaults['_locale'] = $language;
        $defaults['_contao_multilingual_pagetree'] = $language;
        $defaults['pageModel'] = $pageModel;
        unset($defaults['_contao_multilingual_pagetree_fallback'], $defaults['_contao_multilingual_pagetree_canonical_redirect']);

        return new Route(
            $path,
            $defaults,
            $source->getRequirements(),
            $source->getOptions(),
            $host ?? $source->getHost(),
            $source->getSchemes(),
            $source->getMethods(),
        );
    }

    private function mapping(int $rootPageId, string $language): ?LanguageUrlMapping
    {
        return $this->urlResolver?->forLanguage($rootPageId, $language);
    }

    /**
     * The default language needs a route of its own only when its mapping moves
     * it away from what Contao's own route already serves.
     */
    private function needsOwnRoute(?LanguageUrlMapping $mapping, Route $contaoRoute): bool
    {
        if (null === $mapping) {
            return false;
        }

        if ($mapping->hasExplicitEntryPoint() && EntryPointNormalizer::ROOT !== $mapping->effectiveEntryPoint) {
            return true;
        }

        $host = $mapping->effectiveHostname;

        return null !== $host && '' !== $contaoRoute->getHost() && 0 !== strcasecmp($host, $contaoRoute->getHost());
    }

    /**
     * True when a non-default language explicitly claims the legacy prefix of
     * the default language, so the obsolete-prefix redirect must not be built.
     */
    private function prefixIsClaimed(int $rootPageId, string $defaultLanguage): bool
    {
        if (null === $this->urlResolver) {
            return false;
        }

        $legacyPrefix = '/'.strtolower(str_replace('-', '_', $defaultLanguage));

        foreach ($this->urlResolver->mappings($rootPageId)->published() as $mapping) {
            if ($mapping->hasExplicitEntryPoint() && $mapping->effectiveEntryPoint === $legacyPrefix) {
                return true;
            }
        }

        return false;
    }

    private function targetKey(?string $host, string $path): string
    {
        return strtolower((string) $host).'|'.$this->urlPolicy->normalizePath($path);
    }

    private function hostMatchesRequest(?string $host, Request $request): bool
    {
        if (null === $host || '' === $host) {
            return true;
        }

        try {
            return 0 === strcasecmp($host, $request->getHost());
        } catch (\Throwable) {
            return false;
        }
    }

    private function createParametersRoute(Route $source, string $suffix): Route
    {
        $route = clone $source;
        $path = $source->getPath();
        if ($suffix !== '' && str_ends_with($path, $suffix)) {
            $path = substr($path, 0, -strlen($suffix)).'/{parameters}'.$suffix;
        } else {
            $path = rtrim($path, '/').'/{parameters}';
        }
        $route->setPath($path);
        $route->setRequirement('parameters', '.*');
        $route->setDefault('parameters', '');

        return $route;
    }

    private function buildPrefixedDefaultPath(string $sourceAlias, string $language, string $suffix, bool $isRoot): string
    {
        if ($isRoot) {
            return '/'.$language.'/';
        }

        return '/'.$language.'/'.$sourceAlias.$suffix;
    }

    private function localizedRouteName(int $pageId, string $language, bool $isRoot): string
    {
        return sprintf('tl_page.%d.%s%s', $pageId, $language, $isRoot ? '.root' : '');
    }

    private function isRootRoute(string $name, PageModel $pageModel): bool
    {
        return str_ends_with($name, '.root') || $pageModel->type === 'root' || in_array($pageModel->alias, ['index', '/'], true);
    }

    private function getUrlSuffix(Route $route): string
    {
        if ($route instanceof PageRoute) {
            return (string) $route->getUrlSuffix();
        }

        return preg_match('/(\.[a-zA-Z0-9]+)$/', $route->getPath(), $matches) ? $matches[1] : '';
    }
}
