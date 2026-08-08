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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Content;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentValueProvenance;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

/**
 * What the frontend actually renders for a translated content element.
 *
 * The defect this covers: a saved English value was discarded at render time
 * because its provenance was "inherit". Provenance is now derived from the
 * submission, so a real translation renders - and an untranslated field follows
 * the language's own configured rule instead of always showing the source.
 */
class ContentTranslationRenderingTest extends TestCase
{
    private const TABLE = 'tl_content_translation';

    private TranslationOverlayBuilder $builder;
    private ContentValueProvenance $provenance;
    private FieldStateMap $states;

    protected function setUp(): void
    {
        $registry = new TranslationFieldRegistry();
        $this->states = new FieldStateMap();
        $this->builder = new TranslationOverlayBuilder(
            new TranslationOverlayResolver($registry, $this->states),
            $registry,
        );
        $this->provenance = new ContentValueProvenance($this->states);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    private function translation(array $source, array $submitted, array $fields = ['headline', 'text']): array
    {
        return [
            ...$submitted,
            'language' => 'en',
            'fieldStates' => $this->states->encode($this->provenance->derive($submitted, $source, null, $fields)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function source(): array
    {
        return [
            'id' => 5,
            'pid' => 2,
            'ptable' => 'tl_article',
            'type' => 'text',
            'headline' => 'Deutsche Überschrift',
            'text' => '<p>Deutscher Text</p>',
        ];
    }

    /** A saved English value renders in English. This is the reported defect. */
    public function testASavedTranslationRenders(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, [
            'headline' => 'English headline',
            'text' => '<p>English body</p>',
        ]);

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', PageAvailabilityMode::Fallback);

        $this->assertSame('English headline', $row['headline']);
        $this->assertSame('<p>English body</p>', $row['text']);

        // The source row is never modified by building the overlay.
        $this->assertSame('Deutsche Überschrift', $source['headline']);
        $this->assertSame('<p>Deutscher Text</p>', $source['text']);
    }

    /** Structure stays with the source, whatever the translation row carries. */
    public function testStructureStaysConnectedToTheSource(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, ['headline' => 'English headline', 'text' => '<p>English body</p>']);
        $translation['type'] = 'html';
        $translation['ptable'] = 'tl_calendar_events';

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', PageAvailabilityMode::Fallback);

        $this->assertSame('text', $row['type']);
        $this->assertSame('tl_article', $row['ptable']);
        $this->assertSame(2, $row['pid']);
    }

    /** Under the fallback rule an untranslated field shows the source. */
    public function testAnUntranslatedFieldFallsBackUnderTheFallbackRule(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, [
            'headline' => 'English headline',
            'text' => '<p>Deutscher Text</p>',
        ]);

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', PageAvailabilityMode::Fallback);

        $this->assertSame('English headline', $row['headline']);
        $this->assertSame('<p>Deutscher Text</p>', $row['text'], 'The fallback rule renders the source text.');
    }

    /** Under the strict rule an untranslated field renders nothing. */
    public function testAnUntranslatedFieldRendersNothingUnderTheStrictRule(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, [
            'headline' => 'English headline',
            'text' => '<p>Deutscher Text</p>',
        ]);

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', PageAvailabilityMode::Strict);

        $this->assertSame('English headline', $row['headline']);
        $this->assertSame('', $row['text'], 'The strict rule must not render source-language text.');
    }

    /** A deliberately blanked field stays blank under either rule. */
    public function testADeliberateBlankStaysBlank(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, [
            'headline' => 'English headline',
            'text' => '',
        ]);

        $this->assertSame(
            FieldStateMap::EMPTY,
            $this->states->decode($translation['fieldStates'])['text'],
        );

        foreach ([PageAvailabilityMode::Fallback, PageAvailabilityMode::Strict] as $mode) {
            $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', $mode);

            $this->assertSame('', $row['text'], $mode->value);
        }
    }

    /** Without a mode the previous fallback behaviour is preserved. */
    public function testTheDefaultBehaviourIsUnchangedWithoutAMode(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, ['headline' => 'English headline', 'text' => '<p>Deutscher Text</p>']);

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en');

        $this->assertSame('<p>Deutscher Text</p>', $row['text']);
    }

    /** Saving a form of untouched fallback values changes nothing on render. */
    public function testAnUntouchedFallbackSaveRendersTheSource(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, [
            'headline' => 'Deutsche Überschrift',
            'text' => '<p>Deutscher Text</p>',
        ]);

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', PageAvailabilityMode::Fallback);

        $this->assertSame('Deutsche Überschrift', $row['headline']);
        $this->assertSame('<p>Deutscher Text</p>', $row['text']);

        // ...and the source keeps flowing through, because nothing was claimed.
        $updated = $source;
        $updated['text'] = '<p>Neuer deutscher Text</p>';
        $refreshed = $this->builder->buildRow($updated, $translation, self::TABLE, 'en', PageAvailabilityMode::Fallback);

        $this->assertSame('<p>Neuer deutscher Text</p>', $refreshed['text']);
    }

    /**
     * The structural element type reaches the rendered row.
     *
     * The content-element controller picks its renderer from the type, so a
     * translated row that lost the source type would render nothing useful.
     */
    public function testTheSourceElementTypeReachesTheRenderedRow(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, ['headline' => 'English headline', 'text' => '<p>English body</p>']);

        // A translation row that carries the mirrored structural type.
        $translation['type'] = 'text';

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', PageAvailabilityMode::Fallback);

        $this->assertSame('text', $row['type']);
    }

    /** An empty stored type never blanks the source structure. */
    public function testAnEmptyTranslatedTypeCannotBlankTheStructure(): void
    {
        $source = $this->source();
        $translation = $this->translation($source, ['headline' => 'English headline']);
        $translation['type'] = '';

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', PageAvailabilityMode::Fallback);

        $this->assertSame('text', $row['type'], 'The source type stays authoritative.');
    }

    /** Two languages of one source element never share storage. */
    public function testTranslationsOfTwoLanguagesStaySeparate(): void
    {
        $source = $this->source();

        $english = $this->translation($source, ['headline' => 'English headline', 'text' => '<p>English body</p>']);
        $english['language'] = 'en';

        $russian = $this->translation($source, ['headline' => 'Русский заголовок', 'text' => '<p>Русский текст</p>']);
        $russian['language'] = 'ru';

        $englishRow = $this->builder->buildRow($source, $english, self::TABLE, 'en', PageAvailabilityMode::Fallback);
        $russianRow = $this->builder->buildRow($source, $russian, self::TABLE, 'ru', PageAvailabilityMode::Fallback);

        $this->assertSame('English headline', $englishRow['headline']);
        $this->assertSame('Русский заголовок', $russianRow['headline']);
        $this->assertSame('<p>English body</p>', $englishRow['text']);
        $this->assertSame('<p>Русский текст</p>', $russianRow['text']);

        // ...and neither touched the German source.
        $this->assertSame('Deutsche Überschrift', $source['headline']);
        $this->assertSame('<p>Deutscher Text</p>', $source['text']);
    }

    /** Without a translation record the source row is returned untouched. */
    public function testNoTranslationReturnsTheSourceRow(): void
    {
        $source = $this->source();

        $this->assertSame($source, $this->builder->buildRow($source, null, self::TABLE, 'en', PageAvailabilityMode::Fallback));
    }

    /** A serialised translated value survives the round trip. */
    public function testSerialisedValuesRoundTrip(): void
    {
        $source = ['id' => 9, 'type' => 'list', 'listitems' => serialize(['Eins', 'Zwei'])];
        $translation = $this->translation($source, ['listitems' => serialize(['One', 'Two'])], ['listitems']);

        $row = $this->builder->buildRow($source, $translation, self::TABLE, 'en', PageAvailabilityMode::Fallback);

        $this->assertSame(['One', 'Two'], unserialize($row['listitems'], ['allowed_classes' => false]));
    }
}
