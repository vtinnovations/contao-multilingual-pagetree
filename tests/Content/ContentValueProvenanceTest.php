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
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentValueProvenance;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;

/**
 * Provenance replaces the removed per-field selectors.
 *
 * The additional-language form shows the source text when nothing has been
 * translated yet, so "the field was submitted" cannot mean "this is a
 * translation". These assertions cover the comparison that decides it instead.
 */
class ContentValueProvenanceTest extends TestCase
{
    private ContentValueProvenance $provenance;

    protected function setUp(): void
    {
        $this->provenance = new ContentValueProvenance(new FieldStateMap());
    }

    /** An untouched fallback value never becomes a translation. */
    public function testAnUnchangedSourceValueStaysInherited(): void
    {
        $this->assertSame(FieldStateMap::INHERIT, $this->provenance->state('Deutscher Text', 'Deutscher Text'));
        $this->assertSame(FieldStateMap::INHERIT, $this->provenance->state('', ''));
        $this->assertSame(FieldStateMap::INHERIT, $this->provenance->state('', null));
        $this->assertSame(FieldStateMap::INHERIT, $this->provenance->state(null, ''));
    }

    /** A changed value is a real translation. */
    public function testAChangedValueBecomesCustom(): void
    {
        $this->assertSame(FieldStateMap::CUSTOM, $this->provenance->state('English text', 'Deutscher Text'));
        $this->assertSame(FieldStateMap::CUSTOM, $this->provenance->state('<p>English</p>', '<p>Deutsch</p>'));
        $this->assertSame(FieldStateMap::CUSTOM, $this->provenance->state('English', ''));
    }

    /** Blanking a field that had content is a deliberate blank, not a fallback. */
    public function testABlankedValueBecomesEmpty(): void
    {
        $this->assertSame(FieldStateMap::EMPTY, $this->provenance->state('', 'Deutscher Text'));
        $this->assertSame(FieldStateMap::EMPTY, $this->provenance->state('   ', 'Deutscher Text'));
        $this->assertSame(FieldStateMap::EMPTY, $this->provenance->state(null, 'Deutscher Text'));
    }

    /** Serialised and array shapes of the same value compare as equal. */
    public function testSerialisedAndArrayShapesCompareEqual(): void
    {
        $array = ['one', 'two'];
        $serialised = serialize($array);

        $this->assertTrue($this->provenance->equals($array, $serialised));
        $this->assertTrue($this->provenance->equals($serialised, $array));
        $this->assertSame(FieldStateMap::INHERIT, $this->provenance->state($serialised, $array));
        $this->assertSame(FieldStateMap::CUSTOM, $this->provenance->state(serialize(['eins', 'zwei']), $serialised));

        // Key order must not make an identical map look like a translation.
        $this->assertTrue($this->provenance->equals(
            serialize(['b' => '2', 'a' => '1']),
            serialize(['a' => '1', 'b' => '2']),
        ));
    }

    public function testNumericAndBooleanShapesCompareEqual(): void
    {
        $this->assertTrue($this->provenance->equals('1', 1));
        $this->assertTrue($this->provenance->equals(true, '1'));
        $this->assertTrue($this->provenance->equals(false, ''));
        $this->assertFalse($this->provenance->equals('1', '0'));
    }

    /**
     * @dataProvider blankValues
     */
    public function testBlankDetection(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, $this->provenance->isBlank($value));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function blankValues(): iterable
    {
        yield 'null' => [null, true];
        yield 'empty string' => ['', true];
        yield 'whitespace' => ["  \n ", true];
        yield 'empty array' => [[], true];
        yield 'serialised empty array' => [serialize([]), true];
        yield 'array of blanks' => [['', '  '], true];
        yield 'text' => ['x', false];
        yield 'zero' => ['0', false];
        yield 'array with content' => [['', 'x'], false];
    }

    /** The whole map is derived in one pass, and only for approved fields. */
    public function testTheMapIsDerivedForApprovedFieldsOnly(): void
    {
        $map = $this->provenance->derive(
            [
                'headline' => 'English headline',
                'text' => 'Deutscher Text',
                'caption' => '',
                'cssID' => 'injected',
            ],
            [
                'headline' => 'Deutsche Überschrift',
                'text' => 'Deutscher Text',
                'caption' => 'Deutsche Bildunterschrift',
                'cssID' => 'source',
            ],
            null,
            ['headline', 'text', 'caption'],
        );

        $this->assertSame(
            [
                'caption' => FieldStateMap::EMPTY,
                'headline' => FieldStateMap::CUSTOM,
                'text' => FieldStateMap::INHERIT,
            ],
            $map,
        );
        $this->assertArrayNotHasKey('cssID', $map, 'An unapproved field never gets a state.');
    }

    /** A field absent from the submission keeps whatever it already had. */
    public function testAnAbsentFieldKeepsItsStoredState(): void
    {
        $existing = (new FieldStateMap())->encode(['text' => FieldStateMap::CUSTOM]);

        $map = $this->provenance->derive(['headline' => 'x'], ['headline' => 'y', 'text' => 'z'], $existing, ['headline', 'text']);

        $this->assertSame(FieldStateMap::CUSTOM, $map['text']);
        $this->assertSame(FieldStateMap::CUSTOM, $map['headline']);
    }

    /** Saving a form full of fallback values persists no translation at all. */
    public function testSavingAnUntouchedFallbackFormClaimsNothing(): void
    {
        $source = ['headline' => 'Überschrift', 'text' => '<p>Text</p>', 'caption' => 'Bild'];

        $map = $this->provenance->derive($source, $source, null, ['headline', 'text', 'caption']);

        $this->assertSame(
            ['caption' => FieldStateMap::INHERIT, 'headline' => FieldStateMap::INHERIT, 'text' => FieldStateMap::INHERIT],
            $map,
        );
    }
}
