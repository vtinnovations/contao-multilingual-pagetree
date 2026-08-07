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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\EventListener;

use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PublicationChecker;
use Vtinnovations\ContaoMultilingualPagetree\EventListener\ContentTranslationListener;
use Vtinnovations\ContaoMultilingualPagetree\EventListener\PageTranslationListener;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\IncomingLanguageResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageDomainNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\ContentElementRenderPipeline;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeLanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeTranslationRecordLocator;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PageModelMockTrait;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ScopedModelOverlay;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

class PageAvailabilityRenderingTest extends TestCase
{
    use PageModelMockTrait;

    protected function setUp(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['objPage']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['objPage']);
    }

    /** Requirements 33 and 34 */
    public function testStrictUnavailablePageInvokesNotFoundHandlingWithoutRenderingSourceContent(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['title' => 'About us']);
        $listener = $this->listener('strict', [], $page);

        try {
            $listener->onLoadPageDetails([], $page);
            $this->fail('A strict unavailable page must invoke not-found handling.');
        } catch (NotFoundHttpException $exception) {
            $this->assertStringNotContainsString('10', $exception->getMessage(), 'No internal ids may be exposed.');
            $this->assertStringNotContainsString('tl_page', $exception->getMessage());
        }

        $this->assertSame('About us', $page->title, 'No source content is prepared for output.');
    }

    public function testStrictUnavailablePageDoesNotEndUnrelatedPageLookups(): void
    {
        $currentPage = $this->mockRegularPage(10, 1, 'about-us');
        $otherPage = $this->mockRegularPage(11, 1, 'contact');
        $listener = $this->listener('strict', [], $currentPage);

        // loadDetails() also runs for navigation items; those must not 404.
        $listener->onLoadPageDetails([], $otherPage);

        $this->addToAssertionCount(1);
    }

    /** Requirements 35, 36 and 37 */
    public function testFallbackPageRendersSourceStructureInTheRequestedLanguage(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['title' => 'About us', 'pageTitle' => 'About us | Site']);
        $listener = $this->listener('fallback', [], $page);

        $listener->onLoadPageDetails([], $page);

        $this->assertSame('About us', $page->title, 'The source page provides the content.');
        $this->assertSame('about-us', $page->alias, 'The source alias is untouched.');
        $this->assertSame('de', $page->language, 'The requested language stays active.');
        $this->assertSame('de', $page->urlPrefix);
        $this->assertArrayNotHasKey('fieldStates', $page->row(), 'No synthetic translation record is created.');
    }

    public function testAvailableTranslationIsOverlaidBeforeRendering(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['title' => 'About us', 'pageTitle' => '', 'description' => '']);
        $listener = $this->listener('strict', [
            'tl_page_translation|10|de' => $this->pageTranslation(10, [
                'title' => 'Über uns',
                'alias' => 'ueber-uns',
            ], [
                'title' => FieldStateMap::CUSTOM,
                'alias' => FieldStateMap::CUSTOM,
            ]),
        ], $page);

        $listener->onLoadPageDetails([], $page);

        $this->assertSame('Über uns', $page->title);
        $this->assertSame('ueber-uns', $page->alias);
        $this->assertSame(
            'about-us',
            $page->{PageAvailabilityResolver::SOURCE_ALIAS_PROPERTY},
            'The source alias stays reachable for canonical and fallback URLs.',
        );
    }

    public function testUnpublishedTranslationFallsBackInsteadOfOverlayingInFallbackMode(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['title' => 'About us']);
        $translation = $this->pageTranslation(10, ['title' => 'Über uns', 'alias' => 'ueber-uns'], [
            'title' => FieldStateMap::CUSTOM,
            'alias' => FieldStateMap::CUSTOM,
        ]);
        $translation->published = '';

        $listener = $this->listener('fallback', ['tl_page_translation|10|de' => $translation], $page);
        $listener->onLoadPageDetails([], $page);

        $this->assertSame('About us', $page->title);
        $this->assertSame('about-us', $page->alias);
    }

    /**
     * Requirements 38, 39, 40 and 41: content-element translations keep working
     * on a fallback page and never depend on a page translation record.
     */
    public function testContentElementTranslationsStillApplyOnFallbackPages(): void
    {
        $translated = $this->contentElement(1, 'Source text');
        $untranslated = $this->contentElement(2, 'Second source text');
        $emptied = $this->contentElement(3, 'Third source text');

        $overlay = new ScopedModelOverlay();
        $registry = new TranslationFieldRegistry();
        $contentListener = new ContentTranslationListener(
            new FakeLanguageHelper('de', 'en'),
            new TranslationOverlayBuilder(new TranslationOverlayResolver($registry, new FieldStateMap()), $registry),
            $overlay,
            new FakeTranslationRecordLocator([
                'tl_content_translation|1|de' => $this->contentTranslation(1, ['text' => 'Übersetzter Text'], ['text' => FieldStateMap::CUSTOM]),
                'tl_content_translation|3|de' => $this->contentTranslation(3, ['text' => 'ignored'], ['text' => FieldStateMap::EMPTY]),
            ]),
        );

        $pipeline = new ContentElementRenderPipeline($contentListener);

        $this->assertSame('<div class="ce_text">Übersetzter Text</div>', $pipeline->render($translated));
        $this->assertSame('<div class="ce_text">Second source text</div>', $pipeline->render($untranslated));
        $this->assertSame('<div class="ce_text"></div>', $pipeline->render($emptied));
        $this->assertSame(3, $pipeline->renderCount, 'Every element renders exactly once.');
        $this->assertSame('Source text', $translated->text, 'The source record is never modified.');
    }

    /**
     * @param array<string, object> $translations
     */
    private function listener(string $mode, array $translations, PageModel $currentPage): PageTranslationListener
    {
        $request = Request::create('/de/about-us');
        $request->attributes->set('pageModel', $currentPage);
        $request->attributes->set('_contao_multilingual_pagetree', 'de');

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default')->add(1, 'de', $mode);
        $urlPolicy = new CanonicalUrlPolicy();
        $overlayResolver = new TranslationOverlayResolver(new TranslationFieldRegistry(), new FieldStateMap());

        $availabilityResolver = new PageAvailabilityResolver(
            $registry,
            new FakeTranslationRecordLocator($translations),
            $overlayResolver,
            $urlPolicy,
            new PublicationChecker(),
        );

        $urlResolver = new LanguageUrlResolver(
            new LanguageDomainNormalizer(new CanonicalHost()),
            new EntryPointNormalizer(),
        );

        $languageHelper = new LanguageHelper(
            $requestStack,
            $urlPolicy,
            $registry,
            $availabilityResolver,
            new PublicationChecker(),
            new IncomingLanguageResolver($urlResolver),
            $urlResolver,
        );

        return new PageTranslationListener($languageHelper, null, $overlayResolver, new TranslationFieldRegistry(), $availabilityResolver);
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $states
     */
    private function pageTranslation(int $pageId, array $values, array $states): FakeModel
    {
        return new FakeModel('tl_page_translation', array_merge([
            'id' => 900 + $pageId,
            'pid' => $pageId,
            'language' => 'de',
            'published' => '1',
            'start' => '',
            'stop' => '',
            'fieldStates' => json_encode($states, JSON_THROW_ON_ERROR),
        ], $values));
    }

    private function contentElement(int $id, string $text): FakeModel
    {
        return new FakeModel('tl_content', [
            'id' => $id,
            'pid' => 5,
            'ptable' => 'tl_article',
            'type' => 'text',
            'published' => '1',
            'invisible' => '',
            'text' => $text,
        ]);
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $states
     */
    private function contentTranslation(int $sourceId, array $values, array $states): FakeModel
    {
        return new FakeModel('tl_content_translation', array_merge([
            'id' => 800 + $sourceId,
            'pid' => $sourceId,
            'language' => 'de',
            'published' => '1',
            'invisible' => '',
            'fieldStates' => json_encode($states, JSON_THROW_ON_ERROR),
        ], $values));
    }
}
