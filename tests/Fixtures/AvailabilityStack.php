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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PublicationChecker;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailTargetResolverInterface;
use Vtinnovations\ContaoMultilingualPagetree\Detail\SafeQueryParameters;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\AbsoluteUrlBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\HreflangCodeFormatter;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\LanguageMetadataBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Switcher\LanguageSwitcherBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\IncomingLanguageResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageDomainNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

/**
 * The complete point 1/3/5/6 service stack, wired with in-memory doubles.
 */
final class AvailabilityStack
{
    public readonly Request $request;
    public readonly LanguageHelper $languageHelper;
    public readonly PageAvailabilityResolver $pageAvailability;
    public readonly ResourceAvailabilityResolver $resourceResolver;
    public readonly LanguageSwitcherBuilder $switcherBuilder;
    public readonly LanguageMetadataBuilder $metadataBuilder;
    public readonly LanguageUrlResolver $urlResolver;

    /**
     * @param array<string, object> $pageTranslations keyed "tl_page_translation|<id>|<language>"
     * @param array<string, mixed>  $query
     */
    public function __construct(
        FakeSiteLanguageRegistry $registry,
        array $pageTranslations,
        DetailTargetResolverInterface $detailResolver,
        PageModel $page,
        string $activeLanguage,
        string $path = '/',
        array $query = [],
    ) {
        $this->request = Request::create($path.([] === $query ? '' : '?'.http_build_query($query)));
        $this->request->attributes->set('pageModel', $page);
        $this->request->attributes->set('_contao_multilingual_pagetree', $activeLanguage);

        $requestStack = new RequestStack();
        $requestStack->push($this->request);

        $urlPolicy = new CanonicalUrlPolicy();
        $overlayResolver = new TranslationOverlayResolver(new TranslationFieldRegistry(), new FieldStateMap());
        $publicationChecker = new PublicationChecker();

        $this->pageAvailability = new PageAvailabilityResolver(
            $registry,
            new FakeTranslationRecordLocator($pageTranslations),
            $overlayResolver,
            $urlPolicy,
            $publicationChecker,
        );

        // A resolver without a Contao framework produces empty mappings, which
        // is exactly the state of an installation that configures no language
        // URL mapping: the matched route stays authoritative and every path
        // keeps the previous strategy.
        $this->urlResolver = new LanguageUrlResolver(
            new LanguageDomainNormalizer(new CanonicalHost()),
            new EntryPointNormalizer(),
        );

        $this->languageHelper = new LanguageHelper(
            $requestStack,
            $urlPolicy,
            $registry,
            $this->pageAvailability,
            $publicationChecker,
            new IncomingLanguageResolver($this->urlResolver),
            $this->urlResolver,
        );

        $this->resourceResolver = new ResourceAvailabilityResolver(
            $this->languageHelper,
            $this->pageAvailability,
            $detailResolver,
            $urlPolicy,
            new SafeQueryParameters(),
        );

        $codeFormatter = new HreflangCodeFormatter();

        $this->switcherBuilder = new LanguageSwitcherBuilder(
            $this->languageHelper,
            $this->resourceResolver,
            $codeFormatter,
            $urlPolicy,
        );

        $this->metadataBuilder = new LanguageMetadataBuilder(
            $this->languageHelper,
            $this->resourceResolver,
            new AbsoluteUrlBuilder(),
            $codeFormatter,
            $urlPolicy,
        );
    }
}
