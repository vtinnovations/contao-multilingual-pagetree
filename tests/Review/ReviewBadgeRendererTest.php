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
use Vtinnovations\ContaoMultilingualPagetree\Review\ChangedSourceField;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewBadgeRenderer;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewState;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;

class ReviewBadgeRendererTest extends TestCase
{
    private const LABELS = [
        'unreviewed' => 'Not yet reviewed',
        'up_to_date' => 'Up to date',
        'needs_review' => 'Needs review',
        'source_missing' => 'Source record unavailable',
        'reviewedAt' => 'Reviewed on',
        'reviewedBy' => 'Reviewed by',
        'changedFields' => 'Changed source fields',
        'reviewedValue' => 'Reviewed source value',
        'currentValue' => 'Current source value',
        'markReviewed' => 'Mark translation as reviewed',
        'sourceMissing' => 'The connected source record is unavailable.',
        'field_title' => 'Page title',
    ];

    /**
     * Requirements 69, 70 and 71: every state is shown with its own text label.
     *
     * @dataProvider statuses
     */
    public function testEveryStatusRendersItsTranslatedLabel(ReviewStatus $status, string $expected): void
    {
        $badge = (new ReviewBadgeRenderer())->badge($status, self::LABELS);

        $this->assertStringContainsString($expected, $badge);
        $this->assertStringContainsString('contao-multilingual-pagetree-review--'.$status->value, $badge);
    }

    /**
     * @return iterable<string, array{ReviewStatus, string}>
     */
    public static function statuses(): iterable
    {
        yield 'unreviewed' => [ReviewStatus::Unreviewed, 'Not yet reviewed'];
        yield 'up to date' => [ReviewStatus::UpToDate, 'Up to date'];
        yield 'needs review' => [ReviewStatus::NeedsReview, 'Needs review'];
        yield 'source missing' => [ReviewStatus::SourceMissing, 'Source record unavailable'];
    }

    /** Requirement 72: status is never communicated by colour alone. */
    public function testStatusIsNotCommunicatedByColourAlone(): void
    {
        $renderer = new ReviewBadgeRenderer();

        foreach (ReviewStatus::cases() as $status) {
            $badge = $renderer->badge($status, self::LABELS);
            $text = trim(strip_tags($badge));

            $this->assertNotSame('', $text, 'A textual label is always present.');
            $this->assertStringContainsString('title="', $badge);
            $this->assertStringContainsString('aria-hidden="true"', $badge, 'The symbol is decorative next to the text.');
        }
    }

    public function testAnUnknownLabelSetFallsBackToTheStatusValue(): void
    {
        $badge = (new ReviewBadgeRenderer())->badge(ReviewStatus::NeedsReview, []);

        $this->assertStringContainsString('needs_review', $badge);
    }

    /** Requirement 64: every dynamic value in the panel is escaped. */
    public function testPanelEscapesPreviewsAndReviewerNames(): void
    {
        $state = ReviewState::create(
            ReviewStatus::NeedsReview,
            1700000000,
            3,
            str_repeat('a', 64),
            str_repeat('b', 64),
            [new ChangedSourceField('title', 'Old "value"', '<script>alert(1)</script>')],
        );

        $panel = (new ReviewBadgeRenderer())->panel($state, self::LABELS, '/contao?key=contao_multilingual_pagetree_review', '<b>Editor</b>');

        $this->assertStringNotContainsString('<script>', $panel);
        $this->assertStringNotContainsString('<b>Editor</b>', $panel);
        $this->assertStringContainsString('&lt;script&gt;', $panel);
        $this->assertStringContainsString('Page title', $panel, 'Field labels come from the DCA, not from data.');
        $this->assertStringContainsString('Changed source fields', $panel);
    }

    public function testPanelNeverExposesInternalFingerprints(): void
    {
        $revision = str_repeat('a', 64);
        $state = ReviewState::create(ReviewStatus::NeedsReview, 1700000000, 3, $revision, str_repeat('b', 64));

        $panel = (new ReviewBadgeRenderer())->panel($state, self::LABELS, null, 'Editor');

        $this->assertStringNotContainsString($revision, $panel);
    }

    public function testTheReviewActionIsOnlyOfferedForReviewableRecords(): void
    {
        $renderer = new ReviewBadgeRenderer();

        $reviewable = $renderer->panel(ReviewState::create(ReviewStatus::NeedsReview), self::LABELS, '/contao?key=contao_multilingual_pagetree_review');
        $orphaned = $renderer->panel(ReviewState::sourceMissing(), self::LABELS, '/contao?key=contao_multilingual_pagetree_review');

        $this->assertStringContainsString('key=contao_multilingual_pagetree_review', $reviewable);
        $this->assertStringNotContainsString('key=contao_multilingual_pagetree_review', $orphaned);
        $this->assertStringContainsString('The connected source record is unavailable.', $orphaned);
    }

    public function testPanelNeverNestsAFormInsideContaoRecordForm(): void
    {
        $panel = (new ReviewBadgeRenderer())->panel(
            ReviewState::create(ReviewStatus::NeedsReview),
            self::LABELS,
            '/contao?key=contao_multilingual_pagetree_review',
        );

        self::assertStringNotContainsString('<form', $panel);
        self::assertStringContainsString('formaction="/contao?key=contao_multilingual_pagetree_review"', $panel);
        self::assertStringContainsString('formmethod="post"', $panel);
    }

    public function testStandaloneListActionRemainsACsrfProtectedPostForm(): void
    {
        $form = (new ReviewBadgeRenderer())->actionForm('/review', 'token', 'Review');
        self::assertStringContainsString('<form method="post"', $form);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="token"', $form);
    }

    public function testAMissingReviewerShowsANeutralLabelInsteadOfFailing(): void
    {
        $state = ReviewState::create(ReviewStatus::UpToDate, 1700000000, 42, str_repeat('a', 64), str_repeat('a', 64));

        $panel = (new ReviewBadgeRenderer())->panel($state, self::LABELS, null, '#42');

        $this->assertStringContainsString('#42', $panel);
        $this->assertStringContainsString('Reviewed on', $panel);
    }
}
