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

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\EventListener\ArticleTranslationListener;
use Vtinnovations\ContaoMultilingualPagetree\EventListener\ContentTranslationListener;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\ArticleRenderPipeline;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\ContentElementRenderPipeline;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeLanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeTranslationRecordLocator;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ScopedModelOverlay;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

class ArticleTranslationListenerTest extends TestCase
{
    private ScopedModelOverlay $overlay;

    /**
     * The article is rendered by Contao's own article pipeline and the
     * translated article fields are available before that happens.
     * (Requirements 12, 15 and 16)
     */
    public function testArticleIsRenderedThroughTheNormalPipelineWithTranslatedFields(): void
    {
        $article = $this->article(5, ['title' => 'Source title']);
        $records = [
            'tl_article_translation|5|de' => $this->articleTranslation(5, ['title' => 'Übersetzter Titel'], ['title' => FieldStateMap::CUSTOM]),
        ];

        $pipeline = $this->articlePipeline($records);
        $buffer = $pipeline->render($article);

        $this->assertSame(1, $pipeline->renderCount);
        $this->assertSame('Übersetzter Titel', $pipeline->moduleData['title']);
        $this->assertSame('Übersetzter Titel', $pipeline->moduleData['headline'], 'Contao derives the article headline from the title.');
        $this->assertTrue($pipeline->templateUsed);
        $this->assertStringContainsString('<div class="mod_article">', $buffer);
        $this->assertStringContainsString('<h1>Übersetzter Titel</h1>', $buffer);
    }

    /**
     * The content elements of an article are rendered exactly once by the
     * normal content pipeline and are never collected or concatenated by the
     * bundle. (Requirements 13 and 14)
     */
    public function testChildElementsAreRenderedOnceByContao(): void
    {
        $article = $this->article(5, ['title' => 'Source title']);
        $first = $this->contentModel(1, 5, 'First source');
        $second = $this->contentModel(2, 5, 'Second source');

        $records = [
            'tl_article_translation|5|de' => $this->articleTranslation(5, ['title' => 'Übersetzter Titel'], ['title' => FieldStateMap::CUSTOM]),
            'tl_content_translation|1|de' => $this->contentTranslation(1, ['text' => 'Erster Text'], ['text' => FieldStateMap::CUSTOM]),
        ];

        $elements = $this->elementPipeline($records);
        $pipeline = $this->articlePipeline($records, $elements);
        $buffer = $pipeline->render($article, [$first, $second]);

        $this->assertSame(2, $elements->renderCount);
        $this->assertSame(1, $elements->renderCountFor(1));
        $this->assertSame(1, $elements->renderCountFor(2));
        $this->assertSame([1, 2], $elements->renderedIds, 'Element order is Contao\'s responsibility.');
        $this->assertCount(2, $pipeline->templateData['elements']);
        $this->assertSame(
            '<div class="mod_article"><h1>Übersetzter Titel</h1><div class="ce_text">Erster Text</div><div class="ce_text">Second source</div></div>',
            $buffer,
        );
        $this->assertSame(1, substr_count($buffer, 'Erster Text'), 'A translated element must not be rendered twice.');
    }

    public function testArticleOverlayIsReleasedAfterRendering(): void
    {
        $article = $this->article(5, ['title' => 'Source title']);
        $records = [
            'tl_article_translation|5|de' => $this->articleTranslation(5, ['title' => 'Übersetzter Titel'], ['title' => FieldStateMap::CUSTOM]),
        ];

        $pipeline = $this->articlePipeline($records);
        $pipeline->render($article);

        $this->assertSame('Source title', $article->title);
        $this->assertFalse($this->overlay->isActive($article));
    }

    /**
     * Article visibility rules stay intact on the Contao paths that check them.
     * (Requirement 17)
     */
    public function testUnpublishedArticleTranslationIsNotVisible(): void
    {
        $article = $this->article(5, ['title' => 'Source title']);
        $translation = $this->articleTranslation(5, ['title' => 'Übersetzt'], ['title' => FieldStateMap::CUSTOM]);
        $translation->published = '';

        $records = ['tl_article_translation|5|de' => $translation];
        $elements = $this->elementPipeline($records);
        $pipeline = new ArticleRenderPipeline($this->articleListener($records), $elements, true);

        $this->assertSame('', $pipeline->render($article, [$this->contentModel(1, 5, 'Source text')]));
        $this->assertSame(0, $pipeline->renderCount);
        $this->assertSame(0, $elements->renderCount);
    }

    /**
     * On the article column path Contao does not consult isVisibleElement, so an
     * article that is unpublished for the active language loses its headline and
     * its content elements are skipped before they are rendered.
     */
    public function testUnpublishedArticleTranslationSuppressesHeadlineAndElements(): void
    {
        $article = $this->article(5, ['title' => 'Source title']);
        $translation = $this->articleTranslation(5, ['title' => 'Übersetzt'], ['title' => FieldStateMap::CUSTOM]);
        $translation->published = '';

        $records = ['tl_article_translation|5|de' => $translation];
        $elements = $this->elementPipeline($records);
        $pipeline = $this->articlePipeline($records, $elements);

        $buffer = $pipeline->render($article, [$this->contentModel(1, 5, 'Source text')]);

        $this->assertSame('<div class="mod_article"></div>', $buffer);
        $this->assertSame(0, $elements->renderCount, 'Nothing may be rendered and thrown away.');
        $this->assertSame('', $pipeline->moduleData['published']);
        $this->assertSame('Source title', $article->title, 'The overlay is released again.');
    }

    public function testUntranslatedArticleIsRenderedUnchanged(): void
    {
        $article = $this->article(5, ['title' => 'Source title']);
        $pipeline = $this->articlePipeline([]);

        $buffer = $pipeline->render($article, [$this->contentModel(1, 5, 'Source text')]);

        $this->assertSame('Source title', $pipeline->moduleData['title']);
        $this->assertStringContainsString('<h1>Source title</h1>', $buffer);
        $this->assertStringContainsString('Source text', $buffer);
    }

    public function testInheritedArticleTitleFollowsTheSource(): void
    {
        $article = $this->article(5, ['title' => 'Updated source title']);
        $records = [
            'tl_article_translation|5|de' => $this->articleTranslation(5, ['title' => 'Outdated copy'], ['title' => FieldStateMap::INHERIT]),
        ];

        $pipeline = $this->articlePipeline($records);
        $pipeline->render($article);

        $this->assertSame('Updated source title', $pipeline->moduleData['title']);
    }

    public function testOtherTablesAreIgnored(): void
    {
        $records = [];
        $locator = new FakeTranslationRecordLocator($records);
        $listener = $this->articleListener($records, $locator);
        $page = new FakeModel('tl_page', ['id' => 5, 'title' => 'Source']);

        $this->assertTrue($listener->onIsVisibleElement($page, true));
        $this->assertSame([], $locator->calls);
    }

    /**
     * @param array<string, object> $records
     */
    private function articlePipeline(array $records, ?ContentElementRenderPipeline $elements = null): ArticleRenderPipeline
    {
        return new ArticleRenderPipeline(
            $this->articleListener($records),
            $elements ?? $this->elementPipeline($records),
        );
    }

    /**
     * @param array<string, object> $records
     */
    private function articleListener(array $records, ?FakeTranslationRecordLocator $locator = null): ArticleTranslationListener
    {
        $registry = new TranslationFieldRegistry();
        $this->overlay ??= new ScopedModelOverlay();

        return new ArticleTranslationListener(
            new FakeLanguageHelper('de', 'en'),
            new TranslationOverlayBuilder(new TranslationOverlayResolver($registry, new FieldStateMap()), $registry),
            $this->overlay,
            $locator ?? new FakeTranslationRecordLocator($records),
        );
    }

    /**
     * @param array<string, object> $records
     */
    private function elementPipeline(array $records): ContentElementRenderPipeline
    {
        $registry = new TranslationFieldRegistry();
        $this->overlay ??= new ScopedModelOverlay();

        $listener = new ContentTranslationListener(
            new FakeLanguageHelper('de', 'en'),
            new TranslationOverlayBuilder(new TranslationOverlayResolver($registry, new FieldStateMap()), $registry),
            $this->overlay,
            new FakeTranslationRecordLocator($records),
        );

        return new ContentElementRenderPipeline($listener);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function article(int $id, array $data): FakeModel
    {
        return new FakeModel('tl_article', array_merge(
            ['id' => $id, 'pid' => 2, 'inColumn' => 'main', 'published' => '1', 'showTeaser' => ''],
            $data,
        ));
    }

    private function contentModel(int $id, int $articleId, string $text): FakeModel
    {
        return new FakeModel('tl_content', [
            'id' => $id,
            'pid' => $articleId,
            'ptable' => 'tl_article',
            'type' => 'text',
            'sorting' => $id * 16,
            'published' => '1',
            'invisible' => '',
            'text' => $text,
        ]);
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $states
     */
    private function articleTranslation(int $sourceId, array $values, array $states): FakeModel
    {
        return new FakeModel('tl_article_translation', array_merge([
            'id' => 800 + $sourceId,
            'pid' => $sourceId,
            'language' => 'de',
            'published' => '1',
            'invisible' => '',
            'fieldStates' => json_encode($states, JSON_THROW_ON_ERROR),
        ], $values));
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $states
     */
    private function contentTranslation(int $sourceId, array $values, array $states): FakeModel
    {
        return new FakeModel('tl_content_translation', array_merge([
            'id' => 900 + $sourceId,
            'pid' => $sourceId,
            'language' => 'de',
            'published' => '1',
            'invisible' => '',
            'fieldStates' => json_encode($states, JSON_THROW_ON_ERROR),
        ], $values));
    }
}
