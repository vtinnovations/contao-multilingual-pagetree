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

namespace Vtinnovations\ContaoMultilingualPagetree\Detail;

use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Model\CalendarEventsTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Model\FaqTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Model\NewsTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityReason;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

final class DetailTargetUrlResolver implements DetailTargetResolverInterface
{
    public function __construct(
        private readonly DetailRequestDetector $detector,
        private readonly LanguageHelper $languageHelper,
        private readonly TranslationOverlayResolver $overlayResolver,
        private readonly SafeQueryParameters $queryParameters,
        private readonly PageAvailabilityResolver $availabilityResolver,
        private readonly ?LanguageUrlResolver $urlResolver = null,
    ) {
    }

    public function detect(Request $request, PageModel $readerPage): ?DetailContext
    {
        return $this->detector->detect($request, $readerPage);
    }

    public function resolveTargetUrl(Request $request, PageModel $readerPage, string $targetLanguage): ?string
    {
        return $this->resolveTarget($request, $readerPage, $targetLanguage)->url;
    }

    public function resolveTarget(Request $request, PageModel $readerPage, string $targetLanguage): DetailTargetResult
    {
        try {
            return $this->doResolveTarget($request, $readerPage, $targetLanguage);
        } catch (\Throwable) {
            return DetailTargetResult::unavailable(ResourceAvailabilityReason::ResolutionFailed);
        }
    }

    private function doResolveTarget(Request $request, PageModel $readerPage, string $targetLanguage): DetailTargetResult
    {
        $context = $this->detector->detect($request, $readerPage);
        if ($context === null) {
            return DetailTargetResult::unavailable(ResourceAvailabilityReason::NotADetailResource);
        }

        $rootPageId = $readerPage->type === 'root' ? (int) $readerPage->id : (int) $readerPage->rootId;
        if ($rootPageId <= 0 || !$this->languageHelper->isLanguageEnabled($targetLanguage, $rootPageId)) {
            return DetailTargetResult::unavailable(ResourceAvailabilityReason::LanguageNotConfigured);
        }

        $defaultLanguage = $this->languageHelper->getDefaultLanguage($rootPageId);

        // The reader page follows the central strict/fallback page-availability
        // decision. The detail record below keeps its own point 3 rules: page
        // fallback never turns a missing detail translation into a valid target.
        if ($this->availabilityResolver->resolve($readerPage, $targetLanguage)->isUnavailable()) {
            return DetailTargetResult::unavailable(ResourceAvailabilityReason::PageUnavailable);
        }

        $source = $this->detector->findSourceById($context->type, $context->sourceId);
        if ($source === null) {
            return DetailTargetResult::unavailable(ResourceAvailabilityReason::MissingDetailRecord);
        }

        if ($this->languagesEqual($targetLanguage, $defaultLanguage)) {
            $targetAlias = (string) $source->alias;
        } else {
            $translation = $this->findTranslation($context, $targetLanguage);
            if ($translation === null) {
                return DetailTargetResult::unavailable(ResourceAvailabilityReason::MissingDetailTranslation);
            }
            if (!$this->languageHelper->isPublished($translation)) {
                return DetailTargetResult::unavailable(ResourceAvailabilityReason::UnpublishedDetailTranslation);
            }
            $targetAlias = (string) $this->overlayResolver->resolveField(
                $source,
                $translation,
                'alias',
                $this->translationTable($context->type),
            );
        }

        // "0" is a valid alias; only a genuinely empty alias is unusable.
        if ($targetAlias === '') {
            return DetailTargetResult::unavailable(ResourceAvailabilityReason::InvalidDetailAlias);
        }

        $readerPath = $this->languageHelper->getCanonicalPagePath($readerPage, $targetLanguage);
        if ($readerPath === null) {
            return DetailTargetResult::unavailable(ResourceAvailabilityReason::PageUnavailable);
        }

        $segments = [$targetAlias];
        foreach ($context->routeParameters as $parameter) {
            // An occurrence parameter that cannot be represented must never be
            // dropped silently: that would link to a different occurrence.
            if (!is_string($parameter) || '' === $parameter) {
                return DetailTargetResult::unavailable(ResourceAvailabilityReason::UnrepresentableParameters);
            }

            $segments[] = $parameter;
        }

        $path = $this->appendSegmentsBeforeSuffix($readerPath, $segments);

        // The reader path already carries the target language's entry point;
        // only the origin still has to follow the target language.
        $url = $this->urlResolver?->url($this->urlResolver->forLanguage($rootPageId, $targetLanguage), $path, $request)
            ?? $request->getBaseUrl().$path;
        $query = $this->queryParameters->filter($request->query->all());
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return DetailTargetResult::available($request->getBaseUrl().$path, $url, $targetAlias);
    }

    private function findTranslation(DetailContext $context, string $language): ?object
    {
        return match ($context->type) {
            DetailContext::NEWS => NewsTranslationModel::findByPidAndLanguage($context->sourceId, $language),
            DetailContext::EVENT => CalendarEventsTranslationModel::findByPidAndLanguage($context->sourceId, $language),
            DetailContext::FAQ => FaqTranslationModel::findByPidAndLanguage($context->sourceId, $language),
            default => null,
        };
    }

    private function appendSegmentsBeforeSuffix(string $readerPath, array $segments): string
    {
        $encoded = implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), $segments));
        if (preg_match('/(\.[A-Za-z0-9]+)$/', $readerPath, $matches)) {
            return substr($readerPath, 0, -strlen($matches[1])).'/'.$encoded.$matches[1];
        }

        return rtrim($readerPath, '/').'/'.$encoded;
    }

    private function translationTable(string $type): string
    {
        return match ($type) {
            DetailContext::NEWS => 'tl_news_translation',
            DetailContext::EVENT => 'tl_calendar_events_translation',
            DetailContext::FAQ => 'tl_faq_translation',
        };
    }

    private function languagesEqual(string $left, string $right): bool
    {
        return str_replace('-', '_', strtolower($left)) === str_replace('-', '_', strtolower($right));
    }
}
