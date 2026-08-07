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

namespace Vtinnovations\ContaoMultilingualPagetree\Availability;

use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailTargetResolverInterface;
use Vtinnovations\ContaoMultilingualPagetree\Detail\SafeQueryParameters;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

/**
 * The single authority for "is the complete current resource available in this
 * language, and under which URL?".
 *
 * It combines the canonical URL policy (point 1), the detail target-URL
 * resolution (point 3) and the strict/fallback page availability (point 5)
 * without repeating any of their rules. The language switcher and the page
 * metadata both consume this service, so a link and its metadata can never
 * contradict each other.
 */
final class ResourceAvailabilityResolver
{
    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly PageAvailabilityResolver $pageAvailabilityResolver,
        private readonly DetailTargetResolverInterface $detailResolver,
        private readonly CanonicalUrlPolicy $urlPolicy,
        private readonly SafeQueryParameters $safeQueryParameters,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?LanguageUrlResolver $urlResolver = null,
    ) {
    }

    /**
     * Availability of every language configured for the current root site, in
     * configuration order.
     *
     * @return list<ResourceLanguageAvailability>
     */
    public function resolveAll(Request $request, ?PageModel $page = null): array
    {
        $page ??= $this->languageHelper->getCurrentPageModel();

        if (null === $page) {
            return [];
        }

        $rootPageId = $this->rootPageId($page);
        $results = [];

        foreach ($this->languageHelper->getAvailableLanguages($rootPageId) as $configuration) {
            $language = (string) ($configuration['language'] ?? '');

            if ('' === $language) {
                continue;
            }

            $results[] = $this->resolveForLanguage($request, $language, $page);
        }

        return $results;
    }

    public function resolveForLanguage(Request $request, string $targetLanguage, ?PageModel $page = null): ResourceLanguageAvailability
    {
        $page ??= $this->languageHelper->getCurrentPageModel();

        if (null === $page) {
            return ResourceLanguageAvailability::unavailable(
                $targetLanguage,
                ResourceLanguageAvailability::TYPE_PAGE,
                ResourceAvailabilityReason::NoCurrentPage,
            );
        }

        try {
            return $this->doResolve($request, $targetLanguage, $page);
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not resolve the availability of the current resource in "%s": %s',
                $targetLanguage,
                $exception->getMessage(),
            ));

            return ResourceLanguageAvailability::unavailable(
                $targetLanguage,
                ResourceLanguageAvailability::TYPE_PAGE,
                ResourceAvailabilityReason::ResolutionFailed,
            );
        }
    }

    private function doResolve(Request $request, string $targetLanguage, PageModel $page): ResourceLanguageAvailability
    {
        $rootPageId = $this->rootPageId($page);
        $isActive = $this->urlPolicy->languagesEqual($targetLanguage, $this->languageHelper->getActiveLanguage());

        // Languages of another root site never appear.
        if ($rootPageId <= 0 || !$this->languageHelper->isLanguageEnabled($targetLanguage, $rootPageId)) {
            return ResourceLanguageAvailability::unavailable(
                $targetLanguage,
                ResourceLanguageAvailability::TYPE_PAGE,
                ResourceAvailabilityReason::LanguageNotConfigured,
            );
        }

        $pageAvailability = $this->pageAvailabilityResolver->resolve($page, $targetLanguage);
        $detailContext = $this->detailResolver->detect($request, $page);
        $resourceType = null !== $detailContext ? $detailContext->type : ResourceLanguageAvailability::TYPE_PAGE;

        if (null !== $detailContext) {
            $target = $this->detailResolver->resolveTarget($request, $page, $targetLanguage);

            if (!$target->available || null === $target->path || null === $target->url) {
                return $this->unavailableOrActive(
                    $targetLanguage,
                    $resourceType,
                    $target->reason,
                    $pageAvailability,
                    $isActive,
                );
            }

            return $this->availableOrActive(
                $targetLanguage,
                $resourceType,
                $target->path,
                $this->withTargetOrigin($request, $rootPageId, $targetLanguage, $target->url),
                $pageAvailability,
                $isActive,
                $page,
            );
        }

        if ($pageAvailability->isUnavailable()) {
            return $this->unavailableOrActive(
                $targetLanguage,
                $resourceType,
                ResourceAvailabilityReason::PageUnavailable,
                $pageAvailability,
                $isActive,
            );
        }

        $path = $this->pageAvailabilityResolver->canonicalPath($pageAvailability);

        if (null === $path) {
            return $this->unavailableOrActive(
                $targetLanguage,
                $resourceType,
                ResourceAvailabilityReason::PageUnavailable,
                $pageAvailability,
                $isActive,
            );
        }

        return $this->availableOrActive(
            $targetLanguage,
            $resourceType,
            $request->getBaseUrl().$path,
            $this->withTargetOrigin($request, $rootPageId, $targetLanguage, $this->withSafeQuery($request, $request->getBaseUrl().$path)),
            $pageAvailability,
            $isActive,
            $page,
        );
    }

    /**
     * A link that leaves the current protocol or hostname becomes absolute, so
     * it can never carry the source language's origin. A target that stays on
     * the same origin keeps the existing relative form.
     */
    private function withTargetOrigin(Request $request, int $rootPageId, string $targetLanguage, string $url): string
    {
        if (null === $this->urlResolver || '' === $url || 1 === preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            return $url;
        }

        $mapping = $this->urlResolver->forLanguage($rootPageId, $targetLanguage);

        if (null === $mapping || $this->urlResolver->isSameOrigin($mapping, $request)) {
            return $url;
        }

        return ($mapping->canonicalOrigin() ?? '').$url;
    }

    private function availableOrActive(
        string $targetLanguage,
        string $resourceType,
        string $canonicalPath,
        string $url,
        PageAvailabilityResult $pageAvailability,
        bool $isActive,
        PageModel $page,
    ): ResourceLanguageAvailability {
        if (!$isActive) {
            return ResourceLanguageAvailability::available(
                $targetLanguage,
                $resourceType,
                $canonicalPath,
                $url,
                $pageAvailability,
                $pageAvailability->usesFallbackContent(),
            );
        }

        // The active language stays represented even when it is only visible
        // through an authorised preview; it is then never advertised publicly.
        $previewOnly = $this->isPreviewOnly($page, $targetLanguage);

        return ResourceLanguageAvailability::active(
            $targetLanguage,
            $resourceType,
            $canonicalPath,
            $url,
            $pageAvailability,
            $pageAvailability->usesFallbackContent(),
            !$previewOnly,
            $previewOnly,
            ResourceAvailabilityReason::Available,
        );
    }

    private function unavailableOrActive(
        string $targetLanguage,
        string $resourceType,
        ResourceAvailabilityReason $reason,
        PageAvailabilityResult $pageAvailability,
        bool $isActive,
    ): ResourceLanguageAvailability {
        if (!$isActive) {
            return ResourceLanguageAvailability::unavailable($targetLanguage, $resourceType, $reason, $pageAvailability);
        }

        // The language currently being rendered is never dropped from the
        // switcher, but it does not become a public alternate either.
        return ResourceLanguageAvailability::active(
            $targetLanguage,
            $resourceType,
            null,
            null,
            $pageAvailability,
            $pageAvailability->usesFallbackContent(),
            false,
            false,
            $reason,
        );
    }

    /**
     * The current variant is preview-only when it is available with Contao's
     * preview context but not without it.
     */
    private function isPreviewOnly(PageModel $page, string $targetLanguage): bool
    {
        try {
            return $this->pageAvailabilityResolver->resolve($page, $targetLanguage, null, true)->isUnavailable();
        } catch (\Throwable) {
            return false;
        }
    }

    private function withSafeQuery(Request $request, string $url): string
    {
        $query = $this->safeQueryParameters->filter($request->query->all());

        return [] === $query ? $url : $url.'?'.http_build_query($query);
    }

    private function rootPageId(PageModel $page): int
    {
        try {
            return (int) ('root' === $page->type ? $page->id : $page->rootId);
        } catch (\Throwable) {
            return 0;
        }
    }
}
