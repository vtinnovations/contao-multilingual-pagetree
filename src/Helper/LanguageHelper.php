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

namespace Vtinnovations\ContaoMultilingualPagetree\Helper;

use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResult;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PublicationChecker;
use Vtinnovations\ContaoMultilingualPagetree\Availability\SiteLanguageRegistryInterface;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Url\IncomingLanguageResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlMapping;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

class LanguageHelper
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CanonicalUrlPolicy $urlPolicy,
        private readonly SiteLanguageRegistryInterface $siteLanguages,
        private readonly PageAvailabilityResolver $availabilityResolver,
        private readonly PublicationChecker $publicationChecker,
        private readonly IncomingLanguageResolver $incomingLanguages,
        private readonly LanguageUrlResolver $urlResolver,
    ) {
    }

    public function isFrontendRequest(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request || PHP_SAPI === 'cli') {
            return false;
        }

        $scope = $request->attributes->get('_scope') ?? $request->attributes->get('_contao_scope');
        if ($scope === 'backend'
            || $request->attributes->get('_fragment')
            || $request->attributes->get('_preview')
            || $request->attributes->get('_contao_preview')
            || $request->query->has('contao_preview')) {
            return false;
        }

        $path = $request->getPathInfo();

        // Backend, Contao Manager, API, profiler and asset paths are never
        // language-mapped and must never be redirected by this bundle.
        foreach (['/contao', '/_contao', '/_wdt', '/_profiler', '/_fragment', '/bundles/', '/assets/', '/files/', '/system/', '/api/', '/rest/'] as $reserved) {
            if (str_starts_with($path, $reserved)) {
                return false;
            }
        }

        return !str_contains($path, 'contao-manager.phar.php');
    }

    /**
     * The language of the current request.
     *
     * The decision itself belongs to the one central incoming resolver: matched
     * route first, then the persisted language URL mapping of the owning
     * website root, then the site default. This method only supplies the root
     * context and the enabled languages.
     */
    public function getActiveLanguage(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $rootPageId = $this->getRootPageId();
        $defaultLanguage = $this->getDefaultLanguage($rootPageId);
        $enabledLanguages = $this->enabledLanguages($rootPageId, $defaultLanguage);

        return $this->incomingLanguages->resolve($request, $rootPageId, $defaultLanguage, $enabledLanguages);
    }

    /**
     * The language URL mapping of a target language inside the current root.
     */
    public function urlMapping(int $rootPageId, string $language): ?LanguageUrlMapping
    {
        return $this->urlResolver->forLanguage($rootPageId, $language);
    }

    /**
     * True while the root still relies purely on the previous URL strategy, so
     * the legacy language-prefix rules below stay in force.
     */
    public function usesLegacyUrlStrategy(int $rootPageId): bool
    {
        return !$this->urlResolver->mappings($rootPageId)->hasCustomMapping();
    }

    /**
     * The legacy language-code prefix of a request path.
     *
     * It is only meaningful while a root has no configured language URL
     * mapping; as soon as one exists, the mapping - not the language code -
     * decides, and an entry point such as `/languages/de` is not a language
     * code at all.
     */
    public function getLanguagePrefix(Request $request, ?array $enabledLanguages = null): ?string
    {
        if (!preg_match('#^/([a-z]{2}(?:[_-][a-z]{2})?)(?:/|$)#i', rawurldecode($request->getPathInfo()), $matches)) {
            return null;
        }

        $prefix = $this->urlPolicy->normalizeLanguage($matches[1]);
        if ($enabledLanguages === null) {
            return $prefix;
        }

        foreach ($enabledLanguages as $enabledLanguage) {
            if ($prefix !== null && $this->urlPolicy->languagesEqual($prefix, (string) $enabledLanguage)) {
                return (string) $enabledLanguage;
            }
        }

        return null;
    }

    public function hasLanguageLikePrefix(Request $request): bool
    {
        return (bool) preg_match('#^/[a-z]{2}(?:[_-][a-z]{2})?(?:/|$)#i', rawurldecode($request->getPathInfo()));
    }

    /**
     * @return list<string>
     */
    public function enabledLanguages(int $rootPageId, ?string $defaultLanguage = null): array
    {
        $defaultLanguage ??= $this->getDefaultLanguage($rootPageId);
        $languages = array_column($this->getAvailableLanguages($rootPageId), 'language');

        if (!in_array($defaultLanguage, $languages, true)) {
            $languages[] = $defaultLanguage;
        }

        return array_values($languages);
    }

    /**
     * @return list<array{language: string, label: string, flag: string, fallback: bool, mode: string}>
     */
    public function getAvailableLanguages(int $rootPageId): array
    {
        return array_map(
            static fn ($siteLanguage): array => $siteLanguage->toArray(),
            $this->siteLanguages->languages($rootPageId),
        );
    }

    public function getDefaultLanguage(int $rootPageId): string
    {
        return $this->siteLanguages->defaultLanguage($rootPageId);
    }

    public function isDefaultLanguage(?string $language = null, ?int $rootPageId = null): bool
    {
        $rootPageId ??= $this->getRootPageId();

        return $this->urlPolicy->languagesEqual(
            $language ?? $this->getActiveLanguage(),
            $this->getDefaultLanguage($rootPageId),
        );
    }

    public function isLanguageEnabled(string $language, int $rootPageId): bool
    {
        return $this->siteLanguages->isEnabled($rootPageId, $language);
    }

    /**
     * The canonical path of a page in a target language, or null when the page
     * is not available in that language.
     *
     * The decision itself belongs to the central page-availability service; this
     * method only exposes its canonical URL data to the existing callers.
     */
    public function getCanonicalPagePath(PageModel $pageModel, string $targetLanguage, ?string $urlSuffix = null): ?string
    {
        return $this->availabilityResolver->canonicalPath(
            $this->availabilityResolver->resolve($pageModel, $targetLanguage),
            $urlSuffix,
        );
    }

    public function getCurrentPageModel(): ?PageModel
    {
        $request = $this->requestStack->getCurrentRequest();
        $pageModel = $request?->attributes->get('pageModel');
        if ($pageModel instanceof PageModel) {
            return $pageModel;
        }

        return isset($GLOBALS['objPage']) && $GLOBALS['objPage'] instanceof PageModel ? $GLOBALS['objPage'] : null;
    }

    public function isPublished(object|array|null $record): bool
    {
        return $this->publicationChecker->isPublished($record);
    }

    /**
     * The page-availability decision of the central service, for callers that
     * need more than the canonical path.
     */
    public function resolvePageAvailability(PageModel $pageModel, string $targetLanguage): PageAvailabilityResult
    {
        return $this->availabilityResolver->resolve($pageModel, $targetLanguage);
    }

    public function getRootPageId(): int
    {
        $pageModel = $this->getCurrentPageModel();

        return $pageModel instanceof PageModel
            ? (int) ($pageModel->type === 'root' ? $pageModel->id : $pageModel->rootId)
            : 0;
    }
}
