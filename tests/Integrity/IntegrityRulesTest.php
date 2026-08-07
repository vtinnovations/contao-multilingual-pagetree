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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Integrity;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;
use Vtinnovations\ContaoMultilingualPagetree\Content\FreeContentRelationValidator;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssue;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCode;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegritySeverity;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\Rule\FreeContentRule;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\Rule\LanguageConfigurationRule;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\Rule\MetadataIntegrityRule;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\Rule\TranslationRelationRule;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryIntegrityDataSource;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

class IntegrityRulesTest extends TestCase
{
    /** Requirements 11, 12 and 13 */
    public function testLanguageConfigurationProblemsAreDetected(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 1, 'type' => 'root'])
            ->put('tl_inline_language', ['id' => 1, 'pid' => 1, 'language' => 'en', 'fallback' => 1])
            ->put('tl_inline_language', ['id' => 2, 'pid' => 1, 'language' => 'de', 'fallback' => 1])
            ->put('tl_inline_language', ['id' => 3, 'pid' => 1, 'language' => 'de', 'fallback' => 0]);

        $codes = $this->codes((new LanguageConfigurationRule())->scan(IntegrityScope::root(1), $data));

        $this->assertContains(IntegrityIssueCode::DUPLICATE_LANGUAGE_CONFIGURATION, $codes);
        $this->assertContains(IntegrityIssueCode::MULTIPLE_FALLBACK_LANGUAGES, $codes);
    }

    public function testAMissingFallbackLanguageIsDetected(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 1, 'type' => 'root'])
            ->put('tl_inline_language', ['id' => 1, 'pid' => 1, 'language' => 'de', 'fallback' => 0]);

        $this->assertContains(
            IntegrityIssueCode::MISSING_FALLBACK_LANGUAGE,
            $this->codes((new LanguageConfigurationRule())->scan(IntegrityScope::root(1), $data)),
        );
    }

    /** Requirements 16 and 17 */
    public function testInvalidModeValuesAreDetected(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 1, 'type' => 'root'])
            ->put('tl_inline_language', ['id' => 1, 'pid' => 1, 'language' => 'en', 'fallback' => 1])
            ->put('tl_inline_language', [
                'id' => 2, 'pid' => 1, 'language' => 'de', 'fallback' => 0,
                'pageAvailabilityMode' => 'nonsense', 'contentTranslationMode' => 'independent',
            ]);

        $issues = (new LanguageConfigurationRule())->scan(IntegrityScope::root(1), $data);
        $reasons = [];

        foreach ($issues as $issue) {
            if (IntegrityIssueCode::INVALID_LANGUAGE_CONFIGURATION === $issue->code) {
                $reasons[] = $issue->context['reason'] ?? '';
            }
        }

        $this->assertContains('invalid_page_availability_mode', $reasons);
        $this->assertContains('invalid_content_translation_mode', $reasons);
    }

    /** Requirements 14 and 18: separate roots may use the same language. */
    public function testSeparateRootsUsingTheSameLanguageAreValid(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 1, 'type' => 'root'])
            ->put('tl_page', ['id' => 2, 'type' => 'root'])
            ->put('tl_inline_language', ['id' => 1, 'pid' => 1, 'language' => 'de', 'fallback' => 1])
            ->put('tl_inline_language', ['id' => 2, 'pid' => 2, 'language' => 'de', 'fallback' => 1]);

        foreach ([1, 2] as $root) {
            $codes = $this->codes((new LanguageConfigurationRule())->scan(IntegrityScope::root($root), $data));

            $this->assertNotContains(IntegrityIssueCode::DUPLICATE_LANGUAGE_CONFIGURATION, $codes);
            $this->assertNotContains(IntegrityIssueCode::MULTIPLE_FALLBACK_LANGUAGES, $codes);
        }
    }

    /** Requirement 21 */
    public function testAMissingSourceIsDetected(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 10, 'type' => 'regular'])
            ->withPageRoot(10, 1)
            ->put('tl_page_translation', ['id' => 5, 'pid' => 999, 'language' => 'de']);

        $issues = $this->relationRule()->scan(IntegrityScope::root(1), $data);
        $issue = $issues->filterCode(IntegrityIssueCode::MISSING_SOURCE)->all()[0] ?? null;

        $this->assertNotNull($issue);
        $this->assertSame(IntegritySeverity::Error, $issue->severity);
        $this->assertTrue($issue->requiresConfirmation(), 'Deleting an orphan always needs confirmation.');
        $this->assertTrue($issue->destructive);
    }

    /** Requirement 23: a translation may not reference another translation. */
    public function testATranslationReferencingATranslationIsDetected(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 10, 'type' => 'regular'])
            ->withPageRoot(10, 1)
            // The "source" carries translation markers.
            ->put('tl_page', ['id' => 11, 'type' => 'regular', 'language' => 'de', 'fieldStates' => '{}'])
            ->put('tl_page_translation', ['id' => 5, 'pid' => 11, 'language' => 'de']);

        $this->assertContains(
            IntegrityIssueCode::TRANSLATION_SOURCE_RELATION,
            $this->codes($this->relationRule()->scan(IntegrityScope::root(1), $data)),
        );
    }

    /** Requirement 24 */
    public function testACrossSiteRelationIsCritical(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 20, 'type' => 'regular'])
            ->withPageRoot(20, 2)
            ->put('tl_page_translation', ['id' => 5, 'pid' => 20, 'language' => 'de']);

        // Scanning root 1 sees a translation whose source belongs to root 2.
        $data->pageRoots[20] = 2;
        $issues = $this->relationRule()->scan(IntegrityScope::installation(), $data);

        $this->assertNotNull($issues->filterCode(IntegrityIssueCode::MISSING_SOURCE)->all()[0] ?? null);
    }

    /** Requirements 26, 31 and 32 */
    public function testDuplicateTranslationsAreClassifiedByUniqueness(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 10, 'type' => 'regular'])
            ->withPageRoot(10, 1)
            ->put('tl_page_translation', ['id' => 5, 'pid' => 10, 'language' => 'de', 'alias' => 'ueber-uns'])
            // Empty duplicate: no alias, no states, no publication, no review.
            ->put('tl_page_translation', ['id' => 6, 'pid' => 10, 'language' => 'de', 'alias' => '', 'published' => '', 'fieldStates' => '{}']);

        $duplicates = $this->relationRule()->scan(IntegrityScope::root(1), $data)
            ->filterCode(IntegrityIssueCode::DUPLICATE_TRANSLATION)->all();

        $this->assertCount(1, $duplicates);
        $this->assertSame(6, $duplicates[0]->recordId, 'The first record is kept.');
        $this->assertTrue($duplicates[0]->context['redundant']);
        $this->assertTrue($duplicates[0]->requiresConfirmation());
    }

    /**
     * Requirements 33, 34 and 35: a duplicate carrying unique information is
     * never removed automatically.
     *
     * @dataProvider uniqueDuplicates
     */
    public function testDuplicatesWithUniqueInformationRequireAManualDecision(array $overrides): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 10, 'type' => 'regular'])
            ->withPageRoot(10, 1)
            ->put('tl_page_translation', ['id' => 5, 'pid' => 10, 'language' => 'de'])
            ->put('tl_page_translation', array_merge(['id' => 6, 'pid' => 10, 'language' => 'de'], $overrides));

        $duplicate = $this->relationRule()->scan(IntegrityScope::root(1), $data)
            ->filterCode(IntegrityIssueCode::DUPLICATE_TRANSLATION)->all()[0];

        $this->assertFalse($duplicate->context['redundant']);
        $this->assertSame(IntegrityIssue::REPAIR_MANUAL, $duplicate->repairability);
        $this->assertFalse($duplicate->destructive);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function uniqueDuplicates(): iterable
    {
        yield 'custom translation' => [['fieldStates' => '{"title":"custom"}']];
        yield 'explicit empty state' => [['fieldStates' => '{"title":"empty"}']];
        yield 'unique alias' => [['alias' => 'eigener-alias']];
        yield 'own publication state' => [['published' => '1']];
        yield 'review baseline' => [['reviewedSourceRevision' => str_repeat('a', 64)]];
    }

    /** Requirements 51, 52, 53 and 55 */
    public function testInvalidFieldStatesAreDetectedAndNormalised(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page_translation', ['id' => 5, 'pid' => 10, 'language' => 'de', 'fieldStates' => '{not json'])
            ->put('tl_page_translation', ['id' => 6, 'pid' => 10, 'language' => 'de', 'fieldStates' => '{"title":"bogus","colPos":"custom"}']);

        $issues = $this->metadataRule()->scan(IntegrityScope::root(1), $data)
            ->filterCode(IntegrityIssueCode::INVALID_FIELD_STATES)->all();

        $this->assertCount(2, $issues);

        foreach ($issues as $issue) {
            $this->assertTrue($issue->isAutomatic());
            $this->assertFalse($issue->destructive);

            $normalised = json_decode((string) $issue->context['normalised'], true);
            $this->assertIsArray($normalised);

            // Unsupported keys are dropped and invalid states become "inherit".
            $this->assertArrayNotHasKey('colPos', $normalised);

            foreach ($normalised as $state) {
                $this->assertContains($state, [FieldStateMap::INHERIT, FieldStateMap::CUSTOM, FieldStateMap::EMPTY]);
            }
        }
    }

    /** Requirement 58: valid states produce no issue. */
    public function testValidFieldStatesAreNotReported(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page_translation', ['id' => 5, 'pid' => 10, 'language' => 'de', 'fieldStates' => '{"title":"custom"}']);

        $this->assertCount(
            0,
            $this->metadataRule()->scan(IntegrityScope::root(1), $data)->filterCode(IntegrityIssueCode::INVALID_FIELD_STATES),
        );
    }

    /** Requirements 60, 61, 62 and 64 */
    public function testInvalidReviewMetadataIsDetected(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page_translation', [
                'id' => 5, 'pid' => 10, 'language' => 'de',
                TranslationReviewResolver::FIELD_STATUS => 'reviewed_maybe',
                TranslationReviewResolver::FIELD_REVISION => 'not-a-hash',
                TranslationReviewResolver::FIELD_SNAPSHOT => '{broken',
            ])
            // A status claiming a review without a usable baseline.
            ->put('tl_page_translation', [
                'id' => 6, 'pid' => 10, 'language' => 'de',
                TranslationReviewResolver::FIELD_STATUS => 'up_to_date',
                TranslationReviewResolver::FIELD_REVISION => '',
            ]);

        $issues = $this->metadataRule()->scan(IntegrityScope::root(1), $data)
            ->filterCode(IntegrityIssueCode::INVALID_REVIEW_METADATA)->all();

        $this->assertCount(2, $issues);
        $this->assertTrue($issues[0]->isAutomatic());
        $this->assertTrue($issues[1]->context['impossibleStatus']);
    }

    /** Requirement 67: valid review metadata is untouched. */
    public function testValidReviewMetadataIsNotReported(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page_translation', [
                'id' => 5, 'pid' => 10, 'language' => 'de',
                TranslationReviewResolver::FIELD_STATUS => 'up_to_date',
                TranslationReviewResolver::FIELD_REVISION => str_repeat('a', 64),
                TranslationReviewResolver::FIELD_SNAPSHOT => '{"title":{"t":"string","v":"x"}}',
            ]);

        $this->assertCount(
            0,
            $this->metadataRule()->scan(IntegrityScope::root(1), $data)->filterCode(IntegrityIssueCode::INVALID_REVIEW_METADATA),
        );
    }

    /** Requirements 39, 42, 43 and 44 */
    public function testFreeContentRelationProblemsAreDetected(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_content', [])
            ->put('tl_article', ['id' => 1, 'pid' => 900, ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 11, 'pid' => 999, 'ptable' => 'tl_article', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 12, 'pid' => 1, 'ptable' => 'tl_article', ContentOwnership::FIELD_LANGUAGE => 'fr', ContentOwnership::FIELD_ROOT => 1]);

        $codes = $this->codes((new FreeContentRule(new FreeContentRelationValidator()))->scan(IntegrityScope::root(1), $data));

        $this->assertContains(IntegrityIssueCode::ORPHANED_FREE_CONTENT, $codes, 'A missing page and a missing article are reported.');
        $this->assertContains(IntegrityIssueCode::CROSS_LANGUAGE_RELATION, $codes);
    }

    /** Requirements 46 and 47: cycles are detected and never auto-repaired. */
    public function testFreeContentCyclesAreDetected(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_article', [])
            ->put('tl_content', ['id' => 21, 'pid' => 21, 'ptable' => 'tl_content', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 31, 'pid' => 32, 'ptable' => 'tl_content', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 32, 'pid' => 33, 'ptable' => 'tl_content', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 33, 'pid' => 31, 'ptable' => 'tl_content', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1]);

        $cycles = (new FreeContentRule(new FreeContentRelationValidator()))
            ->scan(IntegrityScope::root(1), $data)
            ->filterCode(IntegrityIssueCode::FREE_CONTENT_CYCLE)
            ->all();

        $this->assertNotEmpty($cycles);

        foreach ($cycles as $cycle) {
            $this->assertSame(IntegrityIssue::REPAIR_MANUAL, $cycle->repairability);
            $this->assertSame(IntegritySeverity::Critical, $cycle->severity);
            $this->assertNotEmpty($cycle->relatedIds);
        }
    }

    /** Requirement 48: valid nested free content produces no issue. */
    public function testValidNestedFreeContentPasses(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 900, 'type' => 'regular'])
            ->withPageRoot(900, 1)
            ->put('tl_article', ['id' => 1, 'pid' => 900, ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 11, 'pid' => 1, 'ptable' => 'tl_article', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 12, 'pid' => 11, 'ptable' => 'tl_content', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1]);

        $this->assertTrue(
            (new FreeContentRule(new FreeContentRelationValidator()))->scan(IntegrityScope::root(1), $data)->isEmpty(),
        );
    }

    /** Requirement 53: free records of another root are not scanned. */
    public function testFreeRecordsOfAnotherRootAreNotScanned(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_article', ['id' => 1, 'pid' => 999, ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 2])
            ->put('tl_content', []);

        $this->assertTrue(
            (new FreeContentRule(new FreeContentRelationValidator()))->scan(IntegrityScope::root(1), $data)->isEmpty(),
        );
    }

    private function relationRule(): TranslationRelationRule
    {
        return new TranslationRelationRule(new TranslationFieldRegistry());
    }

    private function metadataRule(): MetadataIntegrityRule
    {
        return new MetadataIntegrityRule(new TranslationFieldRegistry(), new FieldStateMap());
    }

    /**
     * @return list<string>
     */
    private function codes(IntegrityIssueCollection $issues): array
    {
        return array_values(array_unique(array_map(
            static fn (IntegrityIssue $issue): string => $issue->code,
            $issues->all(),
        )));
    }
}
