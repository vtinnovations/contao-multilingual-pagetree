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
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\SourceFingerprintCalculator;
use Vtinnovations\ContaoMultilingualPagetree\Review\SourceValuePreview;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

class TranslationReviewResolverTest extends TestCase
{
    /** Requirement 18 */
    public function testWithoutAReviewedFingerprintTheStateIsUnreviewed(): void
    {
        $state = $this->resolve(['id' => 5, 'pid' => 10], ['title' => 'About us']);

        $this->assertSame(ReviewStatus::Unreviewed, $state->status);
        $this->assertSame([], $state->changedFields);
    }

    /** Requirement 19 */
    public function testMatchingFingerprintsResolveToUpToDate(): void
    {
        $source = ['title' => 'About us', 'alias' => 'about-us'];
        $state = $this->resolve($this->reviewedTranslation($source), $source);

        $this->assertSame(ReviewStatus::UpToDate, $state->status);
        $this->assertSame([], $state->changedFields);
    }

    /** Requirement 20 */
    public function testDifferentFingerprintsResolveToNeedsReview(): void
    {
        $reviewed = ['title' => 'About us', 'alias' => 'about-us'];
        $state = $this->resolve($this->reviewedTranslation($reviewed), ['title' => 'About us Ltd', 'alias' => 'about-us']);

        $this->assertSame(ReviewStatus::NeedsReview, $state->status);
        $this->assertSame(['title'], $state->changedFieldNames());
    }

    /**
     * Requirements 21 and 22: unusable review metadata always falls back to
     * "unreviewed" instead of guessing.
     *
     * @dataProvider unusableBaselines
     */
    public function testUnusableReviewMetadataResolvesToUnreviewed(array $overrides): void
    {
        $translation = array_merge($this->reviewedTranslation(['title' => 'About us']), $overrides);
        $state = $this->resolve($translation, ['title' => 'About us']);

        $this->assertSame(ReviewStatus::Unreviewed, $state->status);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableBaselines(): iterable
    {
        yield 'empty revision' => [[TranslationReviewResolver::FIELD_REVISION => '']];
        yield 'null revision' => [[TranslationReviewResolver::FIELD_REVISION => null]];
        yield 'truncated revision' => [[TranslationReviewResolver::FIELD_REVISION => 'abc123']];
        yield 'non-hex revision' => [[TranslationReviewResolver::FIELD_REVISION => str_repeat('z', 64)]];
        yield 'array revision' => [[TranslationReviewResolver::FIELD_REVISION => ['nonsense']]];
        yield 'invalid status value' => [[TranslationReviewResolver::FIELD_REVISION => '', TranslationReviewResolver::FIELD_STATUS => 'nonsense']];
    }

    /** A stale persisted status can never override the live comparison. */
    public function testAStalePersistedStatusDoesNotOverrideTheComparison(): void
    {
        $translation = $this->reviewedTranslation(['title' => 'About us']);
        $translation[TranslationReviewResolver::FIELD_STATUS] = ReviewStatus::UpToDate->value;

        $state = $this->resolve($translation, ['title' => 'Changed']);

        $this->assertSame(ReviewStatus::NeedsReview, $state->status);
    }

    /** Requirements 23 and 24 */
    public function testAMissingSourceRecordIsReportedWithoutCrashing(): void
    {
        $state = $this->resolve($this->reviewedTranslation(['title' => 'About us']), null);

        $this->assertSame(ReviewStatus::SourceMissing, $state->status);
        $this->assertFalse($state->isReviewable());
        $this->assertFalse($state->sourceAvailable);
    }

    /** Requirements 25 and 26 */
    public function testResolvingDoesNotModifyTheTranslationRecord(): void
    {
        $translation = $this->reviewedTranslation(['title' => 'About us']);
        $translation['title'] = 'Über uns';
        $translation['fieldStates'] = json_encode(['title' => FieldStateMap::CUSTOM], JSON_THROW_ON_ERROR);
        $before = $translation;

        $this->resolve($translation, ['title' => 'Changed source']);

        $this->assertSame($before, $translation, 'Neither values nor field states are touched.');
    }

    /**
     * Requirements 28, 29 and 30: every field state reacts to a source change,
     * and requirements 31 to 33: none of them is modified.
     *
     * @dataProvider fieldStates
     */
    public function testEveryFieldStateProducesNeedsReviewOnASourceChange(string $fieldState): void
    {
        $translation = $this->reviewedTranslation(['title' => 'About us', 'alias' => 'about-us']);
        $translation['fieldStates'] = json_encode(['title' => $fieldState], JSON_THROW_ON_ERROR);
        $translation['title'] = 'custom' === $fieldState ? 'Über uns' : '';

        $state = $this->resolve($translation, ['title' => 'About us Ltd', 'alias' => 'about-us']);

        $this->assertSame(ReviewStatus::NeedsReview, $state->status);
        $this->assertSame(['title'], $state->changedFieldNames());
        $this->assertSame(
            json_encode(['title' => $fieldState], JSON_THROW_ON_ERROR),
            $translation['fieldStates'],
            'The review layer never changes field states.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fieldStates(): iterable
    {
        yield 'inherit' => [FieldStateMap::INHERIT];
        yield 'custom' => [FieldStateMap::CUSTOM];
        yield 'empty' => [FieldStateMap::EMPTY];
    }

    /** Requirement 34 */
    public function testUnsupportedFieldStateEntriesDoNotAffectReview(): void
    {
        $source = ['title' => 'About us'];
        $translation = $this->reviewedTranslation($source);
        $translation['fieldStates'] = json_encode(['obsoleteField' => 'custom', 'colPos' => 'custom'], JSON_THROW_ON_ERROR);

        $this->assertSame(ReviewStatus::UpToDate, $this->resolve($translation, $source)->status);
    }

    /** Requirements 61, 62 and 63 */
    public function testOnlyChangedSupportedFieldsAreReported(): void
    {
        $reviewed = ['title' => 'About us', 'pageTitle' => 'About', 'alias' => 'about-us'];
        $translation = $this->reviewedTranslation($reviewed);

        $state = $this->resolve($translation, [
            'title' => 'About us Ltd',
            'pageTitle' => 'About',
            'alias' => 'about-us-ltd',
            'sorting' => 999,
            'tstamp' => time(),
        ]);

        $this->assertSame(['alias', 'title'], $state->changedFieldNames());
    }

    /** Requirements 64, 65 and 66: previews are plain, safe text. */
    public function testPreviewsAreSanitisedAndSummarised(): void
    {
        $translation = $this->reviewedTranslation([
            'title' => 'Safe title',
            'description' => '<p>Intro</p><script>alert(1)</script>',
        ]);

        $state = $this->resolve($translation, [
            'title' => '<b>New</b> title',
            'description' => "<p>Changed</p>\r\n<p>text</p>",
        ]);

        foreach ($state->changedFields as $field) {
            $this->assertStringNotContainsString('<', $field->reviewedPreview);
            $this->assertStringNotContainsString('<', $field->currentPreview);
            $this->assertStringNotContainsString('script', $field->currentPreview);
        }

        $this->assertNotSame([], $state->changedFields);
    }

    public function testArrayValuesArePreviewedReadably(): void
    {
        $registry = new TranslationFieldRegistry();
        $calculator = new SourceFingerprintCalculator($registry, new CanonicalValueNormalizer());
        $reviewedSource = ['type' => 'list', 'listitems' => serialize(['One', 'Two'])];
        $fingerprint = $calculator->createFingerprint('tl_content_translation', $reviewedSource);

        $translation = [
            'id' => 5,
            'pid' => 10,
            TranslationReviewResolver::FIELD_REVISION => $fingerprint->hash,
            TranslationReviewResolver::FIELD_SNAPSHOT => $fingerprint->toJson(),
        ];

        $state = $this->resolver()->resolve('tl_content_translation', $translation, [
            'type' => 'list',
            'listitems' => serialize(['One', 'Three']),
        ]);

        $this->assertSame(['listitems'], $state->changedFieldNames());
        $this->assertStringContainsString('One', $state->changedFields[0]->currentPreview);
        $this->assertStringNotContainsString('a:2:', $state->changedFields[0]->currentPreview, 'No serialised blob is exposed.');
    }

    /** Requirement 67 */
    public function testAMissingSnapshotYieldsNoFabricatedDiff(): void
    {
        $translation = $this->reviewedTranslation(['title' => 'About us']);
        $translation[TranslationReviewResolver::FIELD_SNAPSHOT] = '{not-json';

        $state = $this->resolve($translation, ['title' => 'Changed']);

        $this->assertSame(ReviewStatus::NeedsReview, $state->status);
        $this->assertSame([], $state->changedFields, 'No difference is invented from a malformed baseline.');
    }

    /** Requirement 68 */
    public function testFieldsOutsideTheCurrentPolicyAreIgnoredInReports(): void
    {
        $translation = $this->reviewedTranslation(['title' => 'About us']);
        $snapshot = json_decode((string) $translation[TranslationReviewResolver::FIELD_SNAPSHOT], true);
        $snapshot['legacyRemovedField'] = ['t' => 'string', 'v' => 'old'];
        $translation[TranslationReviewResolver::FIELD_SNAPSHOT] = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $state = $this->resolve($translation, ['title' => 'Changed']);

        $this->assertSame(['title'], $state->changedFieldNames());
    }

    /** Requirements 90 to 93: the relation is the only path to the source. */
    public function testReviewAlwaysComparesAgainstTheRelatedSourceRecordOnly(): void
    {
        $source = ['title' => 'About us'];
        $translation = $this->reviewedTranslation($source);

        // A same-titled record of another site is irrelevant: the resolver only
        // ever sees the record the relation resolved to.
        $this->assertSame(ReviewStatus::UpToDate, $this->resolve($translation, $source)->status);
        $this->assertSame(ReviewStatus::SourceMissing, $this->resolve($translation, null)->status);
    }

    public function testPersistableStatusNeverLeaksTheInternalSourceMissingState(): void
    {
        $resolver = $this->resolver();
        $state = $resolver->resolve('tl_page_translation', ['id' => 1, 'pid' => 2], null);

        $this->assertSame(ReviewStatus::Unreviewed->value, $resolver->persistableStatus($state));
    }

    /**
     * @param array<string, mixed>      $translation
     * @param array<string, mixed>|null $source
     */
    private function resolve(array $translation, ?array $source): \Vtinnovations\ContaoMultilingualPagetree\Review\ReviewState
    {
        return $this->resolver()->resolve('tl_page_translation', $translation, $source);
    }

    /**
     * A translation reviewed against exactly the given source state.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function reviewedTranslation(array $source): array
    {
        $fingerprint = (new SourceFingerprintCalculator(new TranslationFieldRegistry(), new CanonicalValueNormalizer()))
            ->createFingerprint('tl_page_translation', $source);

        return [
            'id' => 5,
            'pid' => 10,
            'language' => 'de',
            TranslationReviewResolver::FIELD_STATUS => ReviewStatus::UpToDate->value,
            TranslationReviewResolver::FIELD_REVISION => $fingerprint->hash,
            TranslationReviewResolver::FIELD_SNAPSHOT => $fingerprint->toJson(),
            TranslationReviewResolver::FIELD_REVIEWED_AT => 1700000000,
            TranslationReviewResolver::FIELD_REVIEWED_BY => 3,
        ];
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
