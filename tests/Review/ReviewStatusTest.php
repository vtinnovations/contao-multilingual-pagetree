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
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;

class ReviewStatusTest extends TestCase
{
    /**
     * Requirement 21: an invalid persisted status always normalises safely.
     *
     * @dataProvider invalidValues
     */
    public function testInvalidValuesNormaliseToUnreviewed(mixed $value): void
    {
        $this->assertSame(ReviewStatus::Unreviewed, ReviewStatus::fromValue($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'unknown' => ['reviewed'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'boolean' => [true];
        yield 'array' => [['up_to_date']];
    }

    public function testKnownValuesAreAccepted(): void
    {
        $this->assertSame(ReviewStatus::UpToDate, ReviewStatus::fromValue('up_to_date'));
        $this->assertSame(ReviewStatus::NeedsReview, ReviewStatus::fromValue(' NEEDS_REVIEW '));
        $this->assertSame(ReviewStatus::SourceMissing, ReviewStatus::fromValue('source_missing'));
    }

    public function testOnlyEditorialStatesAreOfferedAsFilters(): void
    {
        $this->assertSame(['unreviewed', 'needs_review', 'up_to_date'], ReviewStatus::editorialValues());
        $this->assertNotContains('source_missing', ReviewStatus::editorialValues());
    }

    public function testStatesNeedingAttentionAreMarked(): void
    {
        $this->assertTrue(ReviewStatus::NeedsReview->needsAttention());
        $this->assertTrue(ReviewStatus::Unreviewed->needsAttention());
        $this->assertFalse(ReviewStatus::UpToDate->needsAttention());
    }
}
