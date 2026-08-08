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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Review;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Review\CanonicalValueNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Review\SourceFingerprintCalculator;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

class SourceFingerprintCalculatorTest extends TestCase
{
    /** Requirement 1 */
    public function testIdenticalSourceValuesProduceIdenticalFingerprints(): void
    {
        $calculator = $this->calculator();
        $row = ['id' => 1, 'title' => 'About us', 'pageTitle' => 'About', 'description' => 'Text', 'alias' => 'about-us'];

        $this->assertSame(
            $calculator->createFingerprint('tl_page_translation', $row)->hash,
            $calculator->createFingerprint('tl_page_translation', $row)->hash,
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $calculator->createFingerprint('tl_page_translation', $row)->hash);
    }

    /** Requirement 2 */
    public function testFieldOrderDoesNotAffectTheFingerprint(): void
    {
        $calculator = $this->calculator();

        $first = ['title' => 'About us', 'alias' => 'about-us', 'description' => 'Text'];
        $second = ['description' => 'Text', 'title' => 'About us', 'alias' => 'about-us'];

        $this->assertSame(
            $calculator->createFingerprint('tl_page_translation', $first)->hash,
            $calculator->createFingerprint('tl_page_translation', $second)->hash,
        );
    }

    /**
     * Requirements 3, 4, 6 and 7: unsupported, structural and technical fields
     * never participate.
     *
     * @dataProvider irrelevantChanges
     */
    public function testIrrelevantFieldsDoNotAffectTheFingerprint(array $extra): void
    {
        $calculator = $this->calculator();
        $base = ['title' => 'About us', 'alias' => 'about-us'];

        $this->assertSame(
            $calculator->createFingerprint('tl_page_translation', $base)->hash,
            $calculator->createFingerprint('tl_page_translation', array_merge($base, $extra))->hash,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function irrelevantChanges(): iterable
    {
        yield 'tstamp' => [['tstamp' => 1234567890]];
        yield 'sorting' => [['sorting' => 128]];
        yield 'page type' => [['type' => 'regular']];
        yield 'layout' => [['layout' => 7]];
        yield 'access groups' => [['groups' => 'a:1:{i:0;i:2;}', 'protected' => '1']];
        yield 'publication' => [['published' => '1', 'start' => '123', 'stop' => '456']];
        yield 'unknown field' => [['someThirdPartyField' => 'value']];
    }

    /** Requirement 5 */
    public function testChangingATranslatableFieldChangesTheFingerprint(): void
    {
        $calculator = $this->calculator();

        $this->assertNotSame(
            $calculator->createFingerprint('tl_page_translation', ['title' => 'About us'])->hash,
            $calculator->createFingerprint('tl_page_translation', ['title' => 'About us Ltd'])->hash,
        );
    }

    /**
     * Requirements 8, 9, 10 and 11: semantically different values stay apart.
     *
     * @dataProvider distinctValues
     */
    public function testSemanticallyDifferentValuesStayDistinguishable(mixed $first, mixed $second): void
    {
        $calculator = $this->calculator();

        $this->assertNotSame(
            $calculator->createFingerprint('tl_page_translation', ['title' => $first])->hash,
            $calculator->createFingerprint('tl_page_translation', ['title' => $second])->hash,
        );
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function distinctValues(): iterable
    {
        yield 'zero and empty string' => [0, ''];
        yield 'zero int and zero string' => [0, '0'];
        yield 'false and null' => [false, null];
        yield 'false and zero' => [false, 0];
        yield 'empty array and null' => [[], null];
        yield 'empty array and empty string' => [[], ''];
        yield 'null and empty string' => [null, ''];
    }

    /** Requirement 9 */
    public function testZeroStringIsStable(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(
            $calculator->createFingerprint('tl_page_translation', ['title' => '0'])->hash,
            $calculator->createFingerprint('tl_page_translation', ['title' => '0'])->hash,
        );
        $this->assertNotSame('', $calculator->createFingerprint('tl_page_translation', ['title' => '0'])->hash);
    }

    /** Requirement 12 */
    public function testEquivalentSerialisedArraysProduceEquivalentFingerprints(): void
    {
        $calculator = $this->calculator();

        $serialised = $calculator->createFingerprint('tl_content_translation', [
            'type' => 'list',
            'listitems' => serialize(['b' => 'two', 'a' => 'one']),
        ])->hash;
        $reordered = $calculator->createFingerprint('tl_content_translation', [
            'type' => 'list',
            'listitems' => serialize(['a' => 'one', 'b' => 'two']),
        ])->hash;
        $asArray = $calculator->createFingerprint('tl_content_translation', [
            'type' => 'list',
            'listitems' => ['a' => 'one', 'b' => 'two'],
        ])->hash;

        $this->assertSame($serialised, $reordered, 'Key order inside a serialised array is irrelevant.');
        $this->assertSame($serialised, $asArray, 'A serialised array equals its decoded form.');
    }

    /** Requirement 13 */
    public function testHeadlineStructuresNormaliseDeterministically(): void
    {
        $calculator = $this->calculator();

        $first = $calculator->createFingerprint('tl_content_translation', [
            'type' => 'text',
            'headline' => serialize(['unit' => 'h2', 'value' => 'Title']),
        ])->hash;
        $second = $calculator->createFingerprint('tl_content_translation', [
            'type' => 'text',
            'headline' => serialize(['value' => 'Title', 'unit' => 'h2']),
        ])->hash;
        $different = $calculator->createFingerprint('tl_content_translation', [
            'type' => 'text',
            'headline' => serialize(['value' => 'Title', 'unit' => 'h3']),
        ])->hash;

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $different, 'The headline unit is part of the reviewable state.');
    }

    /** Requirement 14 */
    public function testRichTextLineEndingsAreNormalised(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(
            $calculator->createFingerprint('tl_content_translation', ['type' => 'text', 'text' => "<p>One</p>\r\n<p>Two</p>"])->hash,
            $calculator->createFingerprint('tl_content_translation', ['type' => 'text', 'text' => "<p>One</p>\n<p>Two</p>"])->hash,
        );
        $this->assertNotSame(
            $calculator->createFingerprint('tl_content_translation', ['type' => 'text', 'text' => '<p>One</p>'])->hash,
            $calculator->createFingerprint('tl_content_translation', ['type' => 'text', 'text' => '<p>Two</p>'])->hash,
        );
    }

    /** Requirement 15 */
    public function testMalformedSourceValuesDoNotCrashFingerprinting(): void
    {
        $calculator = $this->calculator();

        $fingerprint = $calculator->createFingerprint('tl_page_translation', [
            'title' => "Invalid \xB1\x31 encoding",
            'description' => new \stdClass(),
            'alias' => ['nested' => ['deep' => ['deeper' => 'value']]],
        ]);

        $this->assertNotSame('', $fingerprint->hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint->hash);
    }

    public function testAMissingSourceFieldIsSkippedInsteadOfInvented(): void
    {
        $calculator = $this->calculator();
        $partial = $calculator->createFingerprint('tl_page_translation', ['title' => 'About us']);

        $this->assertSame(['title'], $partial->fields);
        $this->assertNotSame(
            $partial->hash,
            $calculator->createFingerprint('tl_page_translation', ['title' => 'About us', 'alias' => ''])->hash,
        );
    }

    public function testAnUnknownEntityProducesAnEmptyFingerprint(): void
    {
        $fingerprint = $this->calculator()->createFingerprint('tl_unknown_translation', ['title' => 'x']);

        $this->assertTrue($fingerprint->isEmpty());
        $this->assertFalse($fingerprint->equalsHash(''));
    }

    /** Requirement 16 */
    public function testThirdPartyRegisteredFieldsParticipate(): void
    {
        $calculator = $this->calculator([new ProductNoteFields()]);

        $first = $calculator->createFingerprint('tl_content_translation', ['type' => 'product_note', 'note' => 'One']);
        $second = $calculator->createFingerprint('tl_content_translation', ['type' => 'product_note', 'note' => 'Two']);

        $this->assertContains('note', $first->fields);
        $this->assertNotSame($first->hash, $second->hash);
    }

    /** Requirement 17 */
    public function testProtectedStructuralDeclarationsNeverParticipate(): void
    {
        $calculator = $this->calculator([new ProtectedFieldContributor()]);

        $this->assertSame(
            $calculator->createFingerprint('tl_content_translation', ['type' => 'text', 'text' => 'One', 'colPos' => 'main'])->hash,
            $calculator->createFingerprint('tl_content_translation', ['type' => 'text', 'text' => 'One', 'colPos' => 'left'])->hash,
        );
    }

    public function testContributorOrderDoesNotAffectTheFingerprint(): void
    {
        $row = ['type' => 'product_note', 'note' => 'One'];

        $this->assertSame(
            $this->calculator([new ProductNoteFields(), new ProtectedFieldContributor()])->createFingerprint('tl_content_translation', $row)->hash,
            $this->calculator([new ProtectedFieldContributor(), new ProductNoteFields()])->createFingerprint('tl_content_translation', $row)->hash,
        );
    }

    public function testContentTypeSpecificFieldsAreUsed(): void
    {
        $calculator = $this->calculator();

        // "text" is not translatable for an image element, so it cannot change
        // the reviewable state of one.
        $this->assertSame(
            $calculator->createFingerprint('tl_content_translation', ['type' => 'image', 'alt' => 'A', 'text' => 'One'])->hash,
            $calculator->createFingerprint('tl_content_translation', ['type' => 'image', 'alt' => 'A', 'text' => 'Two'])->hash,
        );
        $this->assertNotSame(
            $calculator->createFingerprint('tl_content_translation', ['type' => 'image', 'alt' => 'A'])->hash,
            $calculator->createFingerprint('tl_content_translation', ['type' => 'image', 'alt' => 'B'])->hash,
        );
    }

    public function testFingerprintsAreCachedPerSourceRecord(): void
    {
        $calculator = $this->calculator();

        $first = $calculator->cachedFingerprint('tl_page_translation', 10, ['title' => 'About us']);
        $second = $calculator->cachedFingerprint('tl_page_translation', 10, ['title' => 'Changed']);

        $this->assertSame($first->hash, $second->hash, 'The cached value is reused within one request.');

        $calculator->reset();

        $this->assertNotSame(
            $first->hash,
            $calculator->cachedFingerprint('tl_page_translation', 10, ['title' => 'Changed'])->hash,
        );
    }

    /**
     * @param list<object> $contributors
     */
    private function calculator(array $contributors = []): SourceFingerprintCalculator
    {
        return new SourceFingerprintCalculator(
            new TranslationFieldRegistry($contributors),
            new CanonicalValueNormalizer(),
        );
    }
}
