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
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewActionResult;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\SourceFingerprintCalculator;
use Vtinnovations\ContaoMultilingualPagetree\Review\SourceValuePreview;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewMarker;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryReviewStorage;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

class TranslationReviewMarkerTest extends TestCase
{
    /** Requirements 35, 36, 37 and 38 */
    public function testMarkingReviewedStoresTheBaselineTimeAndReviewer(): void
    {
        $storage = $this->storage();
        $marker = $this->marker($storage);

        $result = $marker->markReviewed('tl_page_translation', 5, 7);
        $row = $storage->row('tl_page_translation', 5);

        $this->assertTrue($result->successful);
        $this->assertSame(ReviewStatus::UpToDate->value, $row[TranslationReviewResolver::FIELD_STATUS]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $row[TranslationReviewResolver::FIELD_REVISION]);
        $this->assertNotSame('', (string) $row[TranslationReviewResolver::FIELD_SNAPSHOT]);
        $this->assertGreaterThan(0, (int) $row[TranslationReviewResolver::FIELD_REVIEWED_AT]);
        $this->assertSame(7, (int) $row[TranslationReviewResolver::FIELD_REVIEWED_BY]);
    }

    /** Requirement 39 */
    public function testMarkingReviewedTurnsNeedsReviewIntoUpToDate(): void
    {
        $storage = $this->storage();
        $storage->tables['tl_page_translation'][5][TranslationReviewResolver::FIELD_REVISION] = str_repeat('a', 64);
        $storage->tables['tl_page_translation'][5][TranslationReviewResolver::FIELD_STATUS] = ReviewStatus::NeedsReview->value;

        $this->marker($storage)->markReviewed('tl_page_translation', 5, 1);

        $state = $this->resolver()->resolve(
            'tl_page_translation',
            $storage->row('tl_page_translation', 5),
            $storage->row('tl_page', 10),
        );

        $this->assertSame(ReviewStatus::UpToDate, $state->status);
    }

    /** Requirements 40, 41, 42 and 43 */
    public function testMarkingReviewedNeverChangesContentStatesAliasesOrPublication(): void
    {
        $storage = $this->storage();
        $before = $storage->row('tl_page_translation', 5);

        $this->marker($storage)->markReviewed('tl_page_translation', 5, 1);
        $after = $storage->row('tl_page_translation', 5);

        foreach (['title', 'alias', 'fieldStates', 'published', 'start', 'stop', 'language', 'pid'] as $field) {
            $this->assertSame($before[$field], $after[$field], sprintf('"%s" must not change.', $field));
        }
    }

    /** Requirement 46 */
    public function testAnOrphanedTranslationCannotBeMarkedReviewed(): void
    {
        $storage = $this->storage();
        unset($storage->tables['tl_page'][10]);

        $result = $this->marker($storage)->markReviewed('tl_page_translation', 5, 1);

        $this->assertFalse($result->successful);
        $this->assertSame(ReviewActionResult::REASON_SOURCE_MISSING, $result->reason);
        $this->assertSame('', (string) $storage->row('tl_page_translation', 5)[TranslationReviewResolver::FIELD_REVISION]);
    }

    public function testAnUnknownTranslationRecordIsRejected(): void
    {
        $storage = $this->storage();

        $this->assertSame(
            ReviewActionResult::REASON_INVALID_RECORD,
            $this->marker($storage)->markReviewed('tl_page_translation', 999, 1)->reason,
        );
        $this->assertSame(
            ReviewActionResult::REASON_INVALID_RECORD,
            $this->marker($storage)->markReviewed('tl_unknown_translation', 5, 1)->reason,
        );
        $this->assertSame(
            ReviewActionResult::REASON_INVALID_RECORD,
            $this->marker($storage)->markReviewed('tl_page_translation', 0, 1)->reason,
        );
    }

    /** Requirement 93: a translation without a usable relation is never reviewed. */
    public function testATranslationWithoutARelationIsRejected(): void
    {
        $storage = $this->storage();
        $storage->tables['tl_page_translation'][5]['pid'] = 0;

        $this->assertSame(
            ReviewActionResult::REASON_SOURCE_MISSING,
            $this->marker($storage)->markReviewed('tl_page_translation', 5, 1)->reason,
        );
    }

    /**
     * Requirements 48, 53, 55, 57 and 59: a source change marks exactly the
     * translations of that record, in every language, and nothing else.
     */
    public function testASourceChangeMarksOnlyTheTranslationsOfThatRecord(): void
    {
        $storage = $this->storage();
        $storage->put('tl_page_translation', [
            'id' => 6,
            'pid' => 10,
            'language' => 'fr',
            'title' => 'À propos',
            'alias' => 'a-propos',
            'fieldStates' => '{}',
            'published' => '1',
            'start' => '',
            'stop' => '',
            TranslationReviewResolver::FIELD_STATUS => ReviewStatus::Unreviewed->value,
            TranslationReviewResolver::FIELD_REVISION => '',
            TranslationReviewResolver::FIELD_SNAPSHOT => null,
            TranslationReviewResolver::FIELD_REVIEWED_AT => 0,
            TranslationReviewResolver::FIELD_REVIEWED_BY => 0,
        ]);
        // A second site with its own page and translation.
        $storage->put('tl_page', ['id' => 20, 'title' => 'About us', 'alias' => 'about-us']);
        $storage->put('tl_page_translation', [
            'id' => 7,
            'pid' => 20,
            'language' => 'de',
            TranslationReviewResolver::FIELD_STATUS => ReviewStatus::UpToDate->value,
            TranslationReviewResolver::FIELD_REVISION => str_repeat('b', 64),
        ]);

        $marker = $this->marker($storage);
        $marker->markReviewed('tl_page_translation', 5, 1);
        $marker->markReviewed('tl_page_translation', 6, 1);

        // The source record changes.
        $storage->tables['tl_page'][10]['title'] = 'About us Ltd';
        $marker->refreshForSource('tl_page', 10, $storage->row('tl_page', 10));

        $this->assertSame(ReviewStatus::NeedsReview->value, $storage->row('tl_page_translation', 5)[TranslationReviewResolver::FIELD_STATUS]);
        $this->assertSame(ReviewStatus::NeedsReview->value, $storage->row('tl_page_translation', 6)[TranslationReviewResolver::FIELD_STATUS]);
        $this->assertSame(
            ReviewStatus::UpToDate->value,
            $storage->row('tl_page_translation', 7)[TranslationReviewResolver::FIELD_STATUS],
            'Another site keeps its status.',
        );
    }

    /**
     * Requirements 49, 52, 54, 56 and 58: a structural source change never
     * marks a translation.
     *
     * @dataProvider structuralChanges
     */
    public function testStructuralSourceChangesDoNotMarkTranslations(string $field, mixed $value): void
    {
        $storage = $this->storage();
        $marker = $this->marker($storage);
        $marker->markReviewed('tl_page_translation', 5, 1);

        $storage->tables['tl_page'][10][$field] = $value;
        $marker->refreshForSource('tl_page', 10, $storage->row('tl_page', 10));

        $this->assertSame(
            ReviewStatus::UpToDate->value,
            $storage->row('tl_page_translation', 5)[TranslationReviewResolver::FIELD_STATUS],
        );
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function structuralChanges(): iterable
    {
        yield 'sorting' => ['sorting', 512];
        yield 'tstamp' => ['tstamp', 1234567890];
        yield 'layout' => ['layout', 9];
        yield 'access groups' => ['groups', 'a:1:{i:0;i:3;}'];
        yield 'page type' => ['type', 'forward'];
        yield 'publication' => ['published', ''];
    }

    /** Requirement 60: a source change never overwrites translated values. */
    public function testASourceChangeDoesNotOverwriteTranslatedValues(): void
    {
        $storage = $this->storage();
        $marker = $this->marker($storage);
        $marker->markReviewed('tl_page_translation', 5, 1);

        $before = $storage->row('tl_page_translation', 5);
        $storage->tables['tl_page'][10]['title'] = 'About us Ltd';
        $marker->refreshForSource('tl_page', 10, $storage->row('tl_page', 10));
        $after = $storage->row('tl_page_translation', 5);

        $this->assertSame($before['title'], $after['title']);
        $this->assertSame($before['alias'], $after['alias']);
        $this->assertSame($before['fieldStates'], $after['fieldStates']);
    }

    /** Requirement 87: passive status refreshes touch only the status column. */
    public function testRefreshingUsesOneBoundedOperationPerSourceRecord(): void
    {
        $storage = $this->storage();
        $marker = $this->marker($storage);

        $marker->refreshForSource('tl_page', 10, $storage->row('tl_page', 10));
        $marker->refreshForSource('tl_page', 10, $storage->row('tl_page', 10));

        $this->assertSame(2, $storage->statusRefreshes, 'One bounded refresh per source save, not one per translation.');
    }

    /** Requirement 88: a restored record without a baseline is unreviewed. */
    public function testARestoredRecordWithoutABaselineIsUnreviewed(): void
    {
        $storage = $this->storage();
        $storage->tables['tl_page_translation'][5][TranslationReviewResolver::FIELD_STATUS] = ReviewStatus::UpToDate->value;
        $storage->tables['tl_page_translation'][5][TranslationReviewResolver::FIELD_REVISION] = '';

        $state = $this->resolver()->resolve(
            'tl_page_translation',
            $storage->row('tl_page_translation', 5),
            $storage->row('tl_page', 10),
        );

        $this->assertSame(ReviewStatus::Unreviewed, $state->status);
    }

    private function storage(): InMemoryReviewStorage
    {
        return (new InMemoryReviewStorage())
            ->put('tl_page', [
                'id' => 10,
                'title' => 'About us',
                'pageTitle' => 'About',
                'description' => 'Intro',
                'alias' => 'about-us',
                'type' => 'regular',
                'sorting' => 128,
                'tstamp' => 1600000000,
                'published' => '1',
            ])
            ->put('tl_page_translation', [
                'id' => 5,
                'pid' => 10,
                'language' => 'de',
                'title' => 'Über uns',
                'alias' => 'ueber-uns',
                'fieldStates' => json_encode(['title' => FieldStateMap::CUSTOM], JSON_THROW_ON_ERROR),
                'published' => '1',
                'start' => '',
                'stop' => '',
                TranslationReviewResolver::FIELD_STATUS => ReviewStatus::Unreviewed->value,
                TranslationReviewResolver::FIELD_REVISION => '',
                TranslationReviewResolver::FIELD_SNAPSHOT => null,
                TranslationReviewResolver::FIELD_REVIEWED_AT => 0,
                TranslationReviewResolver::FIELD_REVIEWED_BY => 0,
            ]);
    }

    private function marker(InMemoryReviewStorage $storage): TranslationReviewMarker
    {
        $registry = new TranslationFieldRegistry();

        return new TranslationReviewMarker(
            $registry,
            new SourceFingerprintCalculator($registry, new CanonicalValueNormalizer()),
            $storage,
            PackageFactory::grantingPolicy(),
        );
    }

    private function resolver(): TranslationReviewResolver
    {
        $registry = new TranslationFieldRegistry();

        return new TranslationReviewResolver(
            $registry,
            new SourceFingerprintCalculator($registry, new CanonicalValueNormalizer()),
            new SourceValuePreview(),
        );
    }
}
