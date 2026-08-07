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
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceLanguageAvailability;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

/**
 * Builds the canonical and hreflang metadata of the current resource.
 *
 * Only languages in which the complete resource - page *and* detail record - is
 * publicly available become alternates, so a reader page that exists through
 * page fallback never advertises a missing news, event or FAQ translation.
 */
final class LanguageMetadataBuilder
{
    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly ResourceAvailabilityResolver $resourceResolver,
        private readonly AbsoluteUrlBuilder $urlBuilder,
        private readonly HreflangCodeFormatter $codeFormatter,
        private readonly CanonicalUrlPolicy $urlPolicy,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?LanguageUrlResolver $urlResolver = null,
    ) {
    }

    public function build(Request $request, PageModel $page): LanguageMetadata
    {
        try {
            return $this->doBuild($request, $page);
        } catch (\Throwable $exception) {
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: could not build the language metadata: %s', $exception->getMessage()),
            );

            return LanguageMetadata::empty();
        }
    }

    private function doBuild(Request $request, PageModel $page): LanguageMetadata
    {
        $rootPageId = 'root' === $page->type ? (int) $page->id : (int) $page->rootId;
        $defaultLanguage = $this->languageHelper->getDefaultLanguage($rootPageId);

        $alternates = new AlternateLinkSet();
        $canonicalUrl = null;
        $activeFallbackUrl = null;
        $xDefaultUrl = null;

        foreach ($this->resourceResolver->resolveAll($request, $page) as $result) {
            $url = $this->absoluteUrl($page, $request, $result, $rootPageId);

            // The active language keeps a canonical tag even when it is only
            // visible in preview, but it is not advertised as an alternate.
            if ($result->isActive() && null !== $url) {
                $activeFallbackUrl = $url;
            }

            if (!$result->isPubliclyLinkable() || null === $url) {
                continue;
            }

            $code = $this->codeFormatter->format($result->targetLanguage);

            if (null === $code) {
                continue;
            }

            if (!$alternates->add($code, $url)) {
                continue;
            }

            if ($result->isActive()) {
                $canonicalUrl = $url;
            }

            // x-default points at the canonical default-language equivalent of
            // the very same resource, never at a list page.
            if (null === $xDefaultUrl && $this->urlPolicy->languagesEqual($result->targetLanguage, $defaultLanguage)) {
                $xDefaultUrl = $url;
            }
        }

        return new LanguageMetadata($canonicalUrl ?? $activeFallbackUrl, $alternates->all(), $xDefaultUrl);
    }

    private function absoluteUrl(PageModel $page, Request $request, ResourceLanguageAvailability $result, int $rootPageId): ?string
    {
        if (null === $result->canonicalPath) {
            return null;
        }

        // Metadata URLs stay canonical and minimal: no query parameters at all.
        $path = explode('?', $result->canonicalPath, 2)[0];

        // The origin belongs to the language the link points at, never to the
        // language currently being rendered.
        $mapping = $this->urlResolver?->forLanguage($rootPageId, $result->targetLanguage);

        return $this->urlBuilder->build($page, $path, $request, $mapping);
    }
}
