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
use Vtinnovations\ContaoMultilingualPagetree\Integrity\CascadeCleanup;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityCacheInvalidatorInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssue;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCode;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRepairAction;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRepairExecutor;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRepairPlanner;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRepairResult;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityReport;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScanner;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegritySeverity;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityWriterInterface;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryIntegrityDataSource;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

class IntegrityRepairTest extends TestCase
{
    /** Requirement 8: preview and execution use the same plan. */
    public function testPreviewAndExecutionUseTheSamePlan(): void
    {
        $report = $this->report([
            $this->issue(IntegrityIssueCode::INVALID_FIELD_STATES, IntegrityIssue::REPAIR_AUTOMATIC, 5, ['normalised' => '{}']),
            $this->issue(IntegrityIssueCode::MISSING_SOURCE, IntegrityIssue::REPAIR_CONFIRMATION, 6, [], true),
        ]);

        $plan = (new IntegrityRepairPlanner())->plan($report);
        $preview = $plan->preview();

        $this->assertSame(1, $preview['recordsNormalised']);
        $this->assertSame(1, $preview['recordsDeleted']);
        $this->assertTrue($preview['destructive']);
        $this->assertSame($plan->checksum, $preview['checksum']);
        // Previewing twice never changes the plan.
        $this->assertSame($preview, $plan->preview());
    }

    /** Requirement 10: destructive repairs need an explicit confirmation. */
    public function testDestructiveRepairsRequireConfirmation(): void
    {
        $writer = new RecordingWriter();
        $report = $this->report([$this->issue(IntegrityIssueCode::MISSING_SOURCE, IntegrityIssue::REPAIR_CONFIRMATION, 6, [], true)]);
        $plan = (new IntegrityRepairPlanner())->plan($report);

        $result = $this->executor($writer, $report)->execute($plan, false);

        $this->assertSame(IntegrityRepairResult::STATUS_DENIED, $result->status);
        $this->assertSame([], $writer->deleted);
    }

    /** Requirement 9: a stale plan is rejected. */
    public function testAStalePlanIsRejected(): void
    {
        $writer = new RecordingWriter();
        $original = $this->report([$this->issue(IntegrityIssueCode::MISSING_SOURCE, IntegrityIssue::REPAIR_CONFIRMATION, 6, [], true)]);
        $plan = (new IntegrityRepairPlanner())->plan($original);

        // The rescan no longer reports the issue: the data changed meanwhile.
        $result = $this->executor($writer, $this->report([]))->execute($plan, true);

        $this->assertSame(IntegrityRepairResult::STATUS_STALE_PLAN, $result->status);
        $this->assertSame([], $writer->deleted);
    }

    /** Requirements 55, 56, 57 and 64: normalisation only rewrites metadata. */
    public function testNormalisationOnlyWritesMetadataColumns(): void
    {
        $writer = new RecordingWriter();
        $report = $this->report([
            $this->issue(IntegrityIssueCode::INVALID_FIELD_STATES, IntegrityIssue::REPAIR_AUTOMATIC, 5, ['normalised' => '{"title":"inherit"}']),
            $this->issue(IntegrityIssueCode::INVALID_REVIEW_METADATA, IntegrityIssue::REPAIR_AUTOMATIC, 7),
        ]);
        $plan = (new IntegrityRepairPlanner())->plan($report);

        $result = $this->executor($writer, $report)->execute($plan, true);

        $this->assertSame(IntegrityRepairResult::STATUS_COMPLETED, $result->status);
        $this->assertSame(2, $result->normalised);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(['fieldStates' => '{"title":"inherit"}'], $writer->updates[0]['changes']);
        $this->assertSame(['reviewStatus', 'reviewedSourceRevision'], array_keys($writer->updates[1]['changes']));
        $this->assertSame('unreviewed', $writer->updates[1]['changes']['reviewStatus'], 'A record is never marked reviewed.');
    }

    /** Ambiguous relations are quarantined, not deleted. */
    public function testAmbiguousRelationsAreQuarantinedInsteadOfDeleted(): void
    {
        $writer = new RecordingWriter();
        $report = $this->report([
            $this->issue(IntegrityIssueCode::CROSS_SITE_RELATION, IntegrityIssue::REPAIR_CONFIRMATION, 8),
            $this->issue(IntegrityIssueCode::INVALID_FREE_PARENT, IntegrityIssue::REPAIR_CONFIRMATION, 9),
        ]);
        $plan = (new IntegrityRepairPlanner())->plan($report);

        $result = $this->executor($writer, $report)->execute($plan, true);

        $this->assertSame(2, $result->quarantined);
        $this->assertSame(0, $result->deleted);
        $this->assertSame([8, 9], $writer->quarantined);
    }

    /** A duplicate is only deleted when it is provably redundant. */
    public function testOnlyRedundantDuplicatesArePlannedForDeletion(): void
    {
        $planner = new IntegrityRepairPlanner();

        $redundant = $planner->plan($this->report([
            $this->issue(IntegrityIssueCode::DUPLICATE_TRANSLATION, IntegrityIssue::REPAIR_CONFIRMATION, 6, ['redundant' => true], true),
        ]));
        $ambiguous = $planner->plan($this->report([
            $this->issue(IntegrityIssueCode::DUPLICATE_TRANSLATION, IntegrityIssue::REPAIR_MANUAL, 7, ['redundant' => false]),
        ]));

        $this->assertCount(1, $redundant->actions);
        $this->assertSame(IntegrityRepairAction::TYPE_DELETE, $redundant->actions[0]->type);
        $this->assertTrue($ambiguous->isEmpty(), 'An ambiguous duplicate is never planned automatically.');
        $this->assertContains(IntegrityIssueCode::DUPLICATE_TRANSLATION, $ambiguous->unresolved);
    }

    /** Manual issues never become actions. */
    public function testManualIssuesRemainUnresolved(): void
    {
        $plan = (new IntegrityRepairPlanner())->plan($this->report([
            $this->issue(IntegrityIssueCode::FREE_CONTENT_CYCLE, IntegrityIssue::REPAIR_MANUAL, 10),
            $this->issue(IntegrityIssueCode::DUPLICATE_ALIAS, IntegrityIssue::REPAIR_MANUAL, 11),
        ]));

        $this->assertTrue($plan->isEmpty());
        $this->assertContains(IntegrityIssueCode::FREE_CONTENT_CYCLE, $plan->unresolved);
    }

    /** A failed action rolls the transaction back and never claims success. */
    public function testAFailedActionRollsBack(): void
    {
        $writer = new RecordingWriter();
        $writer->failDeletes = true;
        $report = $this->report([$this->issue(IntegrityIssueCode::MISSING_SOURCE, IntegrityIssue::REPAIR_CONFIRMATION, 6, [], true)]);
        $plan = (new IntegrityRepairPlanner())->plan($report);

        $result = $this->executor($writer, $report)->execute($plan, true);

        $this->assertSame(IntegrityRepairResult::STATUS_ROLLED_BACK, $result->status);
        $this->assertTrue($writer->rolledBack);
        $this->assertFalse($result->isSuccessful());
    }

    /** Requirements 123 and 125: only the affected root is invalidated. */
    public function testOnlyTheAffectedRootCacheIsInvalidated(): void
    {
        $writer = new RecordingWriter();
        $cache = new RecordingCacheInvalidator();
        $report = $this->report([$this->issue(IntegrityIssueCode::INVALID_FIELD_STATES, IntegrityIssue::REPAIR_AUTOMATIC, 5, ['normalised' => '{}'])]);
        $plan = (new IntegrityRepairPlanner())->plan($report);

        $executor = new IntegrityRepairExecutor(
            $this->scanner($report),
            new IntegrityRepairPlanner(),
            $writer,
            PackageFactory::grantingPolicy(),
            $cache,
        );
        $result = $executor->execute($plan, true);

        $this->assertTrue($result->cacheInvalidated);
        $this->assertSame([1], $cache->roots);
    }

    /** Requirements 82, 85, 86, 87, 88 and 89: a source cascade is scoped. */
    public function testASourceCascadeOnlyRemovesItsOwnTranslations(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page_translation', ['id' => 1, 'pid' => 10, 'language' => 'de'])
            ->put('tl_page_translation', ['id' => 2, 'pid' => 11, 'language' => 'de'])
            ->put('tl_news_translation', ['id' => 3, 'pid' => 10, 'language' => 'de']);

        $plan = $this->cascade($data, new RecordingWriter())->planForSourceRecord('tl_page', 10, 1);

        $this->assertSame(['tl_page_translation' => 1], $plan->counts());
        $this->assertContains('tl_article', $plan->retained, 'Free content is never part of a source cascade.');
    }

    /** Requirements 91, 100 and 102: a language cascade stays inside its root. */
    public function testALanguageCascadeIsRootAndLanguageScoped(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 10, 'type' => 'regular'])
            ->withPageRoot(10, 1)
            ->put('tl_page', ['id' => 20, 'type' => 'regular'])
            ->withPageRoot(20, 2)
            ->put('tl_page_translation', ['id' => 1, 'pid' => 10, 'language' => 'de'])
            ->put('tl_page_translation', ['id' => 2, 'pid' => 10, 'language' => 'fr'])
            ->put('tl_page_translation', ['id' => 3, 'pid' => 20, 'language' => 'de']);

        $plan = $this->cascade($data, new RecordingWriter())->planForLanguage(1, 'de');

        $this->assertSame(['tl_page_translation' => 1], $plan->counts());
        $this->assertSame('de', $plan->language);
        $this->assertSame(1, $plan->rootPageId);
    }

    /** Requirements 98 and 99: a destructive cascade needs confirmation. */
    public function testADestructiveCascadeRequiresConfirmationAndReportsADryRunCount(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 10, 'type' => 'regular'])
            ->withPageRoot(10, 1)
            ->put('tl_page_translation', ['id' => 1, 'pid' => 10, 'language' => 'de']);

        $writer = new RecordingWriter();
        $cascade = $this->cascade($data, $writer);
        $plan = $cascade->planForLanguage(1, 'de');

        $this->assertSame(1, $plan->total(), 'The dry run reports the exact count.');
        $this->assertSame(IntegrityRepairResult::STATUS_DENIED, $cascade->execute($plan, false)->status);
        $this->assertSame([], $writer->deleted);

        $result = $cascade->execute($plan, true);

        $this->assertSame(IntegrityRepairResult::STATUS_COMPLETED, $result->status);
        $this->assertSame([1], $writer->deleted);
    }

    /** Requirement 93: repeated cascade execution is safe. */
    public function testRepeatedCascadeExecutionIsSafe(): void
    {
        $data = new InMemoryIntegrityDataSource();
        $cascade = $this->cascade($data, new RecordingWriter());

        $this->assertSame(IntegrityRepairResult::STATUS_NOTHING_TO_DO, $cascade->execute($cascade->planForLanguage(1, 'de'), true)->status);
        $this->assertSame(IntegrityRepairResult::STATUS_NOTHING_TO_DO, $cascade->execute($cascade->planForLanguage(1, 'de'), true)->status);
    }

    private function cascade(InMemoryIntegrityDataSource $data, IntegrityWriterInterface $writer): CascadeCleanup
    {
        return new CascadeCleanup(new TranslationFieldRegistry(), $data, $writer, PackageFactory::grantingPolicy());
    }

    private function executor(IntegrityWriterInterface $writer, IntegrityReport $rescan): IntegrityRepairExecutor
    {
        return new IntegrityRepairExecutor($this->scanner($rescan), new IntegrityRepairPlanner(), $writer, PackageFactory::grantingPolicy());
    }

    private function scanner(IntegrityReport $report): IntegrityScanner
    {
        return new class($report) extends IntegrityScanner {
            public function __construct(private readonly IntegrityReport $report)
            {
                parent::__construct(new IntegrityRuleRegistry([]), new InMemoryIntegrityDataSource());
            }

            public function scan(IntegrityScope $scope): IntegrityReport
            {
                return $this->report;
            }
        };
    }

    /**
     * @param list<IntegrityIssue> $issues
     */
    private function report(array $issues): IntegrityReport
    {
        return new IntegrityReport(IntegrityScope::root(1), new IntegrityIssueCollection($issues));
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function issue(
        string $code,
        string $repairability,
        int $recordId,
        array $context = [],
        bool $destructive = false,
    ): IntegrityIssue {
        return new IntegrityIssue(
            $code,
            IntegritySeverity::Error,
            'page',
            'tl_page_translation',
            $recordId,
            1,
            'de',
            $repairability,
            $destructive,
            'tl_page',
            10,
            $context,
        );
    }
}

final class RecordingWriter implements IntegrityWriterInterface
{
    /** @var list<array{table: string, id: int, changes: array<string, mixed>}> */
    public array $updates = [];

    /** @var list<int> */
    public array $quarantined = [];

    /** @var list<int> */
    public array $deleted = [];

    public bool $failDeletes = false;
    public bool $rolledBack = false;
    public bool $committed = false;

    public function updateRecord(string $table, int $id, array $changes): bool
    {
        $this->updates[] = ['table' => $table, 'id' => $id, 'changes' => $changes];

        return true;
    }

    public function quarantineRecord(string $table, int $id): bool
    {
        $this->quarantined[] = $id;

        return true;
    }

    public function deleteRecord(string $table, int $id): bool
    {
        if ($this->failDeletes) {
            return false;
        }

        $this->deleted[] = $id;

        return true;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): void
    {
        $this->committed = true;
    }

    public function rollBack(): void
    {
        $this->rolledBack = true;
    }

    public function supportsTransactions(): bool
    {
        return true;
    }
}

final class RecordingCacheInvalidator implements IntegrityCacheInvalidatorInterface
{
    /** @var list<int> */
    public array $roots = [];

    /** @var list<int> */
    public array $pages = [];

    public function invalidateRoot(int $rootPageId): void
    {
        $this->roots[] = $rootPageId;
    }

    public function invalidatePage(int $pageId): void
    {
        $this->pages[] = $pageId;
    }
}
