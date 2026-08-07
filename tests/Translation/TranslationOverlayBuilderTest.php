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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

class TranslationOverlayBuilderTest extends TestCase
{
    /**
     * A missing translation record must return the untouched source rendering
     * data so Contao renders the source record. (Requirement 7)
     */
    public function testReturnsSourceRowWhenNoTranslationExists(): void
    {
        $source = $this->contentModel(['text' => 'Source text']);

        $row = $this->builder()->buildRow($source, null, 'tl_content_translation', 'de');

        $this->assertSame($source->row(), $row);
    }

    /**
     * An inherited field reflects the current source value. (Requirement 11)
     */
    public function testInheritedFieldReflectsLatestSourceValue(): void
    {
        $source = $this->contentModel(['text' => 'Updated source']);
        $translation = $this->translation(['text' => 'Outdated copy'], ['text' => FieldStateMap::INHERIT]);

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('Updated source', $row['text']);
    }

    public function testCustomFieldOverridesSourceValue(): void
    {
        $source = $this->contentModel(['text' => 'Source text']);
        $translation = $this->translation(['text' => 'Übersetzter Text'], ['text' => FieldStateMap::CUSTOM]);

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('Übersetzter Text', $row['text']);
    }

    /**
     * A custom "0" is a value, not an empty translation. (Requirement 9)
     */
    public function testCustomZeroStringIsPassedThroughUnchanged(): void
    {
        $source = $this->contentModel(['text' => 'Source text']);
        $translation = $this->translation(['text' => '0'], ['text' => FieldStateMap::CUSTOM]);

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('0', $row['text']);
    }

    /**
     * A custom boolean false is a value as well. (Requirement 10)
     */
    public function testCustomFalseIsPassedThroughUnchanged(): void
    {
        $source = $this->contentModel(['text' => 'Source text']);
        $translation = $this->translation(['text' => false], ['text' => FieldStateMap::CUSTOM]);

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertFalse($row['text']);
    }

    /**
     * An explicit "empty" state reaches the renderer as a field appropriate
     * empty value instead of the source value. (Requirement 8)
     */
    public function testEmptyStateProducesFieldAppropriateEmptyValue(): void
    {
        $source = $this->contentModel([
            'text' => 'Source text',
            'headline' => serialize(['value' => 'Source headline', 'unit' => 'h2']),
        ]);
        $translation = $this->translation(
            ['text' => 'ignored', 'headline' => 'ignored'],
            ['text' => FieldStateMap::EMPTY, 'headline' => FieldStateMap::EMPTY],
        );

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('', $row['text']);
        $this->assertSame(serialize(['value' => '', 'unit' => 'h2']), $row['headline']);

        $codeSource = $this->contentModel(['type' => 'code', 'code' => serialize(['first', 'second'])]);
        $codeTranslation = $this->translation(['code' => 'ignored'], ['code' => FieldStateMap::EMPTY]);
        $codeRow = $this->builder()->buildRow($codeSource, $codeTranslation, 'tl_content_translation', 'de');
        $this->assertSame(serialize([]), $codeRow['code']);
    }

    /**
     * Malformed field-state data must not break rendering: everything falls
     * back to the inherited source value.
     */
    public function testMalformedFieldStatesFallBackToInherit(): void
    {
        $source = $this->contentModel(['text' => 'Source text']);
        $translation = new FakeModel('tl_content_translation', [
            'id' => 7,
            'pid' => 1,
            'language' => 'de',
            'fieldStates' => '{not-json',
            'text' => 'Never used',
        ]);

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('Source text', $row['text']);
    }

    public function testSourceRecordIsNotMutated(): void
    {
        $source = $this->contentModel(['text' => 'Source text']);
        $translation = $this->translation(['text' => 'Übersetzter Text'], ['text' => FieldStateMap::CUSTOM]);

        $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('Source text', $source->text);
    }

    public function testTranslatedMediaMetadataIsFoldedIntoTheMetaField(): void
    {
        $source = $this->contentModel([
            'type' => 'image',
            'singleSRC' => 'files/image.jpg',
            'meta' => serialize(['en' => ['title' => 'Source title', 'alt' => 'Source alt', 'caption' => '', 'link' => '']]),
        ]);
        $translation = $this->translation(
            ['alt' => 'Deutscher Alt-Text', 'imageTitle' => 'Deutscher Titel', 'caption' => '', 'titleText' => '', 'url' => ''],
            ['alt' => FieldStateMap::CUSTOM, 'imageTitle' => FieldStateMap::CUSTOM],
        );

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');
        $meta = unserialize($row['meta'], ['allowed_classes' => false]);

        $this->assertSame('Deutscher Alt-Text', $meta['de']['alt']);
        $this->assertSame('Deutscher Titel', $meta['de']['title']);
        $this->assertSame('Source alt', $meta['en']['alt'], 'The source language metadata must stay untouched.');
        $this->assertSame('1', $row['overwriteMeta']);
    }

    public function testStructuralFieldsArePreserved(): void
    {
        $source = $this->contentModel([
            'type' => 'accordionStart',
            'sorting' => 128,
            'ptable' => 'tl_article',
            'cssID' => serialize(['my-id', 'my-class']),
            'text' => 'Source text',
        ]);
        $translation = $this->translation(['text' => 'Übersetzt'], ['text' => FieldStateMap::CUSTOM]);

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('accordionStart', $row['type']);
        $this->assertSame(128, $row['sorting']);
        $this->assertSame('tl_article', $row['ptable']);
        $this->assertSame(serialize(['my-id', 'my-class']), $row['cssID']);
    }

    public function testUnsupportedStoredValuesAndStatesAreIgnored(): void
    {
        $source = $this->contentModel(['type' => 'text', 'customTpl' => 'source_template', 'text' => 'Source']);
        $translation = $this->translation(
            ['customTpl' => 'unsafe_template', 'text' => 'Übersetzt'],
            ['customTpl' => FieldStateMap::CUSTOM, 'text' => FieldStateMap::CUSTOM],
        );

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('source_template', $row['customTpl']);
        $this->assertSame('Übersetzt', $row['text']);
    }

    public function testFieldFromAnotherContentTypeIsNotOverlaid(): void
    {
        $source = $this->contentModel(['type' => 'image', 'text' => 'Structural source value']);
        $translation = $this->translation(['text' => 'Not allowed'], ['text' => FieldStateMap::CUSTOM]);

        $row = $this->builder()->buildRow($source, $translation, 'tl_content_translation', 'de');

        $this->assertSame('Structural source value', $row['text']);
    }

    private function builder(): TranslationOverlayBuilder
    {
        $registry = new TranslationFieldRegistry();

        return new TranslationOverlayBuilder(
            new TranslationOverlayResolver($registry, new FieldStateMap()),
            $registry,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function contentModel(array $data): FakeModel
    {
        return new FakeModel('tl_content', array_merge(
            ['id' => 1, 'pid' => 5, 'ptable' => 'tl_article', 'type' => 'text'],
            $data,
        ));
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $states
     */
    private function translation(array $values, array $states): FakeModel
    {
        return new FakeModel('tl_content_translation', array_merge([
            'id' => 7,
            'pid' => 1,
            'language' => 'de',
            'published' => '1',
            'fieldStates' => json_encode($states, JSON_THROW_ON_ERROR),
        ], $values));
    }
}
