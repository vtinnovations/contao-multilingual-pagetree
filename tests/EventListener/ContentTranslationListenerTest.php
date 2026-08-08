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
use Vtinnovations\ContaoMultilingualPagetree\EventListener\ContentTranslationListener;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\ContentElementRenderPipeline;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeLanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeTranslationRecordLocator;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ScopedModelOverlay;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldPolicyContributorInterface;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistration;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordLocatorInterface;

class ContentTranslationListenerTest extends TestCase
{
    private ScopedModelOverlay $overlay;

    /**
     * A legacy content element is rendered once and the renderer receives the
     * translated values. (Requirements 1 and 3)
     */
    public function testLegacyElementIsRenderedOnceWithTranslatedValues(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text']);
        $registry = new TranslationFieldRegistry([new class implements TranslationFieldPolicyContributorInterface {
            public function registrations(): iterable
            {
                yield new TranslationFieldRegistration('tl_content', 'text', 'string', 'my_fragment');
            }
        }]);
        $listener = $this->listener([
            'tl_content_translation|1|de' => $this->translation(1, ['text' => 'Übersetzter Text'], ['text' => FieldStateMap::CUSTOM]),
        ], registry: $registry);

        $pipeline = new ContentElementRenderPipeline(
            $listener,
            // A legacy ContentElement reads its values from the model row.
            static fn (object $model): string => '<div class="ce_text">'.(string) $model->text.'</div>',
        );

        $buffer = $pipeline->render($element);

        $this->assertSame(1, $pipeline->renderCount);
        $this->assertSame('<div class="ce_text">Übersetzter Text</div>', $buffer);
        $this->assertSame('Übersetzter Text', $pipeline->rowFor(1)['text']);
    }

    /**
     * A fragment controller element goes through the same overlay and is also
     * rendered exactly once. (Requirement 2)
     */
    public function testFragmentControllerElementIsRenderedOnceWithTranslatedValues(): void
    {
        $element = $this->contentModel(1, ['type' => 'my_fragment', 'text' => 'Source text']);
        $registry = new TranslationFieldRegistry([new class implements TranslationFieldPolicyContributorInterface {
            public function registrations(): iterable
            {
                yield new TranslationFieldRegistration('tl_content', 'text', 'string', 'my_fragment');
            }
        }]);
        $listener = $this->listener([
            'tl_content_translation|1|de' => $this->translation(1, ['text' => 'Übersetzter Text'], ['text' => FieldStateMap::CUSTOM]),
        ], registry: $registry);

        $controllerCalls = 0;
        $requestAttributes = [];

        $pipeline = new ContentElementRenderPipeline(
            $listener,
            static function (object $model) use (&$controllerCalls, &$requestAttributes): string {
                // Contao forwards the model as a request attribute to the fragment
                // controller, which reads its data from that model.
                ++$controllerCalls;
                $requestAttributes = ['contentModel' => $model, 'section' => 'main'];

                return '<section>'.(string) $requestAttributes['contentModel']->text.'</section>';
            },
        );

        $buffer = $pipeline->render($element);

        $this->assertSame(1, $controllerCalls);
        $this->assertSame(1, $pipeline->renderCount);
        $this->assertSame('<section>Übersetzter Text</section>', $buffer);
        $this->assertSame('main', $requestAttributes['section'], 'The request context must stay intact.');
    }

    /**
     * The rendered buffer is never replaced by the bundle. (Requirement 5)
     */
    public function testRenderedBufferIsReturnedUnchanged(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text']);
        $listener = $this->listener([
            'tl_content_translation|1|de' => $this->translation(1, ['text' => 'Übersetzt'], ['text' => FieldStateMap::CUSTOM]),
        ]);

        $marker = '<!-- rendered by Contao -->';
        $pipeline = new ContentElementRenderPipeline($listener, static fn (): string => $marker);

        $this->assertSame($marker, $pipeline->render($element));
    }

    /**
     * Without a translation record the source rendering data is used.
     * (Requirements 6 and 7)
     */
    public function testUntranslatedElementUsesSourceRendering(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text']);
        $pipeline = new ContentElementRenderPipeline($this->listener([]));

        $this->assertSame('<div class="ce_text">Source text</div>', $pipeline->render($element));
        $this->assertSame('Source text', $element->text);
    }

    public function testDefaultLanguageIsNeverOverlaid(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text']);
        $locator = new FakeTranslationRecordLocator([
            'tl_content_translation|1|en' => $this->translation(1, ['text' => 'Never used'], ['text' => FieldStateMap::CUSTOM]),
        ]);
        $listener = $this->listener([], 'en', 'en', true, $locator);

        $pipeline = new ContentElementRenderPipeline($listener);

        $this->assertSame('<div class="ce_text">Source text</div>', $pipeline->render($element));
        $this->assertSame([], $locator->calls, 'No translation lookup must happen for the default language.');
    }

    /**
     * The backend and the backend preview are not frontend requests, so the
     * source record is rendered unchanged. (Requirement 26)
     */
    public function testNonFrontendRequestIsNeverOverlaid(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text']);
        $listener = $this->listener(
            ['tl_content_translation|1|de' => $this->translation(1, ['text' => 'Übersetzt'], ['text' => FieldStateMap::CUSTOM])],
            'de',
            'en',
            false,
        );

        $pipeline = new ContentElementRenderPipeline($listener);

        $this->assertSame('<div class="ce_text">Source text</div>', $pipeline->render($element));
    }

    /**
     * An element that is unpublished for the active language is not rendered at
     * all - no buffer is produced and thrown away.
     */
    public function testUnpublishedTranslationPreventsRendering(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text']);
        $translation = $this->translation(1, ['text' => 'Übersetzt'], ['text' => FieldStateMap::CUSTOM]);
        $translation->published = '';

        $listener = $this->listener(['tl_content_translation|1|de' => $translation]);
        $pipeline = new ContentElementRenderPipeline($listener);

        $this->assertSame('', $pipeline->render($element));
        $this->assertSame(0, $pipeline->renderCount);
    }

    public function testInvisibleSourceElementStaysHidden(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text', 'invisible' => '1']);
        $listener = $this->listener([
            'tl_content_translation|1|de' => $this->translation(1, ['text' => 'Übersetzt'], ['text' => FieldStateMap::CUSTOM]),
        ]);

        // Contao has already decided that the source element is invisible.
        $this->assertFalse($listener->onIsVisibleElement($element, false));
        $this->assertSame('Source text', $element->text);
    }

    public function testElementsOfAnArticleUnpublishedInTheActiveLanguageAreSkipped(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text', 'pid' => 5, 'ptable' => 'tl_article']);
        $articleTranslation = new FakeModel('tl_article_translation', [
            'id' => 3, 'pid' => 5, 'language' => 'de', 'published' => '', 'fieldStates' => '{}',
        ]);

        $listener = $this->listener([
            'tl_article_translation|5|de' => $articleTranslation,
            'tl_content_translation|1|de' => $this->translation(1, ['text' => 'Übersetzt'], ['text' => FieldStateMap::CUSTOM]),
        ]);
        $pipeline = new ContentElementRenderPipeline($listener);

        $this->assertSame('', $pipeline->render($element));
        $this->assertSame(0, $pipeline->renderCount);
    }

    /**
     * Supported false-like values survive, while fields belonging to other
     * content types are ignored by the default-deny policy.
     */
    public function testFieldStatesReachTheRenderer(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text', 'html' => '<b>x</b>', 'summary' => 'Source summary']);
        $listener = $this->listener([
            'tl_content_translation|1|de' => $this->translation(
                1,
                ['text' => '0', 'html' => false, 'summary' => 'ignored'],
                ['text' => FieldStateMap::CUSTOM, 'html' => FieldStateMap::CUSTOM, 'summary' => FieldStateMap::EMPTY],
            ),
        ]);

        $pipeline = new ContentElementRenderPipeline($listener, static fn (): string => '');
        $pipeline->render($element);
        $row = $pipeline->rowFor(1);

        $this->assertSame('0', $row['text']);
        $this->assertSame('<b>x</b>', $row['html']);
        $this->assertSame('Source summary', $row['summary']);
    }

    /**
     * An unknown/third-party content type is handed to Contao unchanged: the
     * bundle has no type switch, never selects a renderer and does not overlay
     * fields until a contributor explicitly registers them for that type.
     * (Requirements 31, 32, 33 and 34)
     */
    public function testUnknownContentTypeIsDelegatedToContao(): void
    {
        $element = $this->contentModel(1, ['type' => 'vendor_unknown_element', 'text' => 'Source text']);
        $listener = $this->listener([
            'tl_content_translation|1|de' => $this->translation(1, ['text' => 'Übersetzt'], ['text' => FieldStateMap::CUSTOM]),
        ]);

        $seenTypes = [];
        $pipeline = new ContentElementRenderPipeline(
            $listener,
            static function (object $model) use (&$seenTypes): string {
                // A service based element with a non-standard constructor: only
                // the rendering pipeline may create it, never the bundle.
                $seenTypes[] = (string) $model->type;

                return '[vendor]'.(string) $model->text;
            },
        );

        $this->assertSame('[vendor]Source text', $pipeline->render($element));
        $this->assertSame(['vendor_unknown_element'], $seenTypes);
        $this->assertSame(1, $pipeline->renderCount);
    }

    /**
     * Nested elements keep their order and nesting, each translated on its own,
     * and nothing leaks between them.
     * (Requirements 18, 19, 20, 21, 22 and 23)
     */
    public function testNestedElementsKeepOrderNestingAndIndependentTranslations(): void
    {
        $start = $this->contentModel(10, ['type' => 'accordionStart', 'text' => 'Wrapper source', 'pid' => 5]);
        $translated = $this->contentModel(11, ['type' => 'text', 'text' => 'Child source', 'pid' => 10, 'ptable' => 'tl_content']);
        $untranslated = $this->contentModel(12, ['type' => 'text', 'text' => 'Second child source', 'pid' => 10, 'ptable' => 'tl_content']);
        $stop = $this->contentModel(13, ['type' => 'accordionStop', 'text' => 'Wrapper stop', 'pid' => 5]);

        $listener = $this->listener([
            'tl_content_translation|10|de' => $this->translation(10, ['text' => 'Wrapper übersetzt'], ['text' => FieldStateMap::CUSTOM]),
            'tl_content_translation|11|de' => $this->translation(11, ['text' => 'Kind übersetzt'], ['text' => FieldStateMap::CUSTOM]),
        ]);

        $children = [10 => [$translated, $untranslated]];
        $pipeline = new ContentElementRenderPipeline(
            $listener,
            static function (object $model, ContentElementRenderPipeline $pipeline) use ($children): string {
                $inner = '';

                foreach ($children[(int) $model->id] ?? [] as $child) {
                    $inner .= $pipeline->render($child);
                }

                return '<div data-id="'.(int) $model->id.'">'.(string) $model->text.$inner.'</div>';
            },
        );

        $buffer = $pipeline->render($start).$pipeline->render($stop);

        $this->assertSame(
            '<div data-id="10">Wrapper source<div data-id="11">Kind übersetzt</div><div data-id="12">Second child source</div></div>'
            .'<div data-id="13">Wrapper stop</div>',
            $buffer,
        );
        $this->assertSame([10, 11, 12, 13], $pipeline->renderedIds, 'Nesting order and parent-child relations must not change.');
        $this->assertSame(1, $pipeline->renderCountFor(11));

        foreach ([$start, $translated, $untranslated, $stop] as $model) {
            $this->assertFalse($this->overlay->isActive($model));
        }

        $this->assertSame('Wrapper source', $start->text);
        $this->assertSame('Child source', $translated->text);
        $this->assertSame(10, $translated->pid, 'Parent-child relations stay untouched.');
    }

    /**
     * Rendering one language must not leave translated values on the shared
     * model instance for the next language. (Requirement 28)
     */
    public function testRenderingOneLanguageDoesNotMutateTheModelForAnother(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text']);

        $german = new ContentElementRenderPipeline($this->listener(
            ['tl_content_translation|1|de' => $this->translation(1, ['text' => 'Deutscher Text'], ['text' => FieldStateMap::CUSTOM])],
            'de',
        ));
        $french = new ContentElementRenderPipeline($this->listener(
            ['tl_content_translation|1|fr' => $this->translation(1, ['text' => 'Texte français'], ['text' => FieldStateMap::CUSTOM])],
            'fr',
        ));

        $this->assertSame('<div class="ce_text">Deutscher Text</div>', $german->render($element));
        $this->assertSame('Source text', $element->text, 'The shared model must be released after rendering.');
        $this->assertSame('<div class="ce_text">Texte français</div>', $french->render($element));
        $this->assertSame('Source text', $element->text);
    }

    /**
     * A failing translation lookup falls back to Contao's normal rendering of
     * the source record instead of surfacing an exception.
     */
    public function testFailingTranslationLookupFallsBackToSourceRendering(): void
    {
        $element = $this->contentModel(1, ['type' => 'text', 'text' => 'Source text']);

        $locator = new class() implements TranslationRecordLocatorInterface {
            public function find(string $translationTable, int $sourceId, string $language, ?int $parentId = null): ?object
            {
                throw new \RuntimeException('Database is gone');
            }
        };

        $listener = $this->listener([], 'de', 'en', true, $locator);
        $pipeline = new ContentElementRenderPipeline($listener);

        $this->assertSame('<div class="ce_text">Source text</div>', $pipeline->render($element));
        $this->assertSame(1, $pipeline->renderCount);
    }

    public function testOtherTablesAreIgnored(): void
    {
        $module = new FakeModel('tl_module', ['id' => 1, 'type' => 'navigation']);
        $locator = new FakeTranslationRecordLocator([]);
        $listener = $this->listener([], 'de', 'en', true, $locator);

        $this->assertTrue($listener->onIsVisibleElement($module, true));
        $this->assertSame([], $locator->calls);
    }

    /**
     * @param array<string, object> $records
     */
    private function listener(
        array $records,
        string $language = 'de',
        string $defaultLanguage = 'en',
        bool $frontendRequest = true,
        ?TranslationRecordLocatorInterface $locator = null,
        ?TranslationFieldRegistry $registry = null,
    ): ContentTranslationListener {
        $registry ??= new TranslationFieldRegistry();
        $this->overlay = new ScopedModelOverlay();

        return new ContentTranslationListener(
            new FakeLanguageHelper($language, $defaultLanguage, $frontendRequest),
            new TranslationOverlayBuilder(new TranslationOverlayResolver($registry, new FieldStateMap()), $registry),
            $this->overlay,
            $locator ?? new FakeTranslationRecordLocator($records),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function contentModel(int $id, array $data): FakeModel
    {
        return new FakeModel('tl_content', array_merge(
            ['id' => $id, 'pid' => 5, 'ptable' => 'tl_article', 'sorting' => $id * 16, 'published' => '1', 'invisible' => ''],
            $data,
        ));
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $states
     */
    private function translation(int $sourceId, array $values, array $states): FakeModel
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
