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
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityDataSourceInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssue;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCode;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScanner;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegritySeverity;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryIntegrityDataSource;

class IntegrityScannerTest extends TestCase
{
    /** Requirement 1: discovery is deterministic. */
    public function testRulesAreDiscoveredDeterministically(): void
    {
        $registry = new IntegrityRuleRegistry([
            new StubRule('zulu', 10),
            new StubRule('alpha', 10),
            new StubRule('high', 99),
            new \stdClass(),
        ]);

        $this->assertSame(['high', 'alpha', 'zulu'], $registry->names());
        $this->assertSame($registry->names(), $registry->names(), 'Repeated discovery is stable.');
    }

    /** Requirement 2: scanning never modifies data. */
    public function testScanningIsReadOnly(): void
    {
        $data = (new InMemoryIntegrityDataSource())
            ->put('tl_page', ['id' => 1, 'type' => 'root'])
            ->put('tl_inline_language', ['id' => 1, 'pid' => 1, 'language' => 'en', 'fallback' => 1]);

        $before = $data->snapshot();
        $this->scanner($data, [new StubRule('reader', 10)])->scan(IntegrityScope::root(1));

        $this->assertSame($before, $data->snapshot());
    }

    /** Requirement 3: an unknown entity type simply yields no rules. */
    public function testUnknownEntityTypesFailSafely(): void
    {
        $data = new InMemoryIntegrityDataSource();
        $report = $this->scanner($data, [new StubRule('reader', 10, ['page'])])
            ->scan(IntegrityScope::root(1, null, 'does_not_exist'));

        $this->assertSame([], $report->executedRules);
        $this->assertTrue($report->issues->isEmpty());
    }

    /** Requirement 4: one broken rule never aborts the scan. */
    public function testAFailingRuleIsReportedAndOtherRulesStillRun(): void
    {
        $report = $this->scanner(new InMemoryIntegrityDataSource(), [
            new ThrowingRule(),
            new StubRule('healthy', 10),
        ])->scan(IntegrityScope::root(1));

        $this->assertSame(['healthy'], $report->executedRules);
        $this->assertSame(['broken'], $report->failedRules);
        $this->assertCount(1, $report->issues->filterCode(IntegrityIssueCode::RULE_FAILURE));
    }

    /** Requirement 5: issue codes are stable and known. */
    public function testIssueCodesAreStable(): void
    {
        foreach ([
            IntegrityIssueCode::MISSING_SOURCE,
            IntegrityIssueCode::DUPLICATE_TRANSLATION,
            IntegrityIssueCode::CROSS_SITE_RELATION,
            IntegrityIssueCode::INVALID_FIELD_STATES,
            IntegrityIssueCode::INVALID_REVIEW_METADATA,
            IntegrityIssueCode::FREE_CONTENT_CYCLE,
        ] as $code) {
            $this->assertTrue(IntegrityIssueCode::isKnown($code), $code);
        }

        $this->assertFalse(IntegrityIssueCode::isKnown('invented_code'));
    }

    /**
     * Requirement 6: severity normalises safely and orders correctly.
     *
     * @dataProvider severityValues
     */
    public function testSeverityNormalisation(mixed $value, IntegritySeverity $expected): void
    {
        $this->assertSame($expected, IntegritySeverity::fromValue($value));
    }

    /**
     * @return iterable<string, array{mixed, IntegritySeverity}>
     */
    public static function severityValues(): iterable
    {
        yield 'critical' => ['critical', IntegritySeverity::Critical];
        yield 'padded' => ['  ERROR ', IntegritySeverity::Error];
        yield 'unknown' => ['catastrophic', IntegritySeverity::Info];
        yield 'null' => [null, IntegritySeverity::Info];
        yield 'integer' => [3, IntegritySeverity::Info];
    }

    public function testSeverityOrdering(): void
    {
        $this->assertTrue(IntegritySeverity::Critical->isAtLeast(IntegritySeverity::Error));
        $this->assertFalse(IntegritySeverity::Warning->isAtLeast(IntegritySeverity::Error));
        $this->assertTrue(IntegritySeverity::Error->blocksExitCode());
        $this->assertFalse(IntegritySeverity::Warning->blocksExitCode());
    }

    /** Requirement 7: repairability is explicit on every issue. */
    public function testRepairabilityIsExplicit(): void
    {
        $automatic = $this->issue(IntegrityIssue::REPAIR_AUTOMATIC);
        $confirm = $this->issue(IntegrityIssue::REPAIR_CONFIRMATION);
        $manual = $this->issue(IntegrityIssue::REPAIR_MANUAL);
        $none = $this->issue(IntegrityIssue::REPAIR_NONE);

        $this->assertTrue($automatic->isRepairable());
        $this->assertTrue($automatic->isAutomatic());
        $this->assertTrue($confirm->isRepairable());
        $this->assertTrue($confirm->requiresConfirmation());
        $this->assertFalse($manual->isRepairable());
        $this->assertFalse($none->isRepairable());
    }

    /** Requirement 121: the exit code reflects the highest severity. */
    public function testExitCodesReflectSeverity(): void
    {
        $clean = $this->scanner(new InMemoryIntegrityDataSource(), [new StubRule('empty', 1)])->scan(IntegrityScope::root(1));
        $this->assertSame(0, $clean->exitCode());

        $warning = $this->scanner(new InMemoryIntegrityDataSource(), [
            new StubRule('warn', 1, [], [$this->issue(IntegrityIssue::REPAIR_AUTOMATIC, IntegritySeverity::Warning)]),
        ])->scan(IntegrityScope::root(1));
        $this->assertSame(1, $warning->exitCode());

        $error = $this->scanner(new InMemoryIntegrityDataSource(), [
            new StubRule('err', 1, [], [$this->issue(IntegrityIssue::REPAIR_NONE, IntegritySeverity::Critical)]),
        ])->scan(IntegrityScope::root(1));
        $this->assertSame(2, $error->exitCode());
    }

    /** Requirement 112: an installation-wide scope is explicitly marked. */
    public function testInstallationWideScanRequiresElevatedPermission(): void
    {
        $this->assertTrue(IntegrityScope::installation()->requiresElevatedPermission());
        $this->assertFalse(IntegrityScope::root(1)->requiresElevatedPermission());
        $this->assertFalse(IntegrityScope::page(1, 5)->requiresElevatedPermission());
    }

    /** Requirement 126/127: scopes never cross root sites. */
    public function testScopesNeverCrossRootSites(): void
    {
        $scope = IntegrityScope::root(1);

        $this->assertTrue($scope->coversRoot(1));
        $this->assertFalse($scope->coversRoot(2));
        $this->assertTrue(IntegrityScope::installation()->coversRoot(2));
    }

    public function testReportsAggregateBySeverityAndEntity(): void
    {
        $report = $this->scanner(new InMemoryIntegrityDataSource(), [
            new StubRule('mixed', 1, [], [
                $this->issue(IntegrityIssue::REPAIR_NONE, IntegritySeverity::Error, 'page'),
                $this->issue(IntegrityIssue::REPAIR_NONE, IntegritySeverity::Info, 'content'),
            ]),
        ])->scan(IntegrityScope::root(1));

        $this->assertSame(2, $report->issues->count());
        $this->assertSame(1, $report->issues->countsBySeverity()['error']);
        $this->assertSame(['content' => 1, 'page' => 1], $report->issues->countsByEntity());
        $this->assertSame(IntegritySeverity::Error, $report->issues->highestSeverity());
    }

    /**
     * @param list<object> $rules
     */
    private function scanner(IntegrityDataSourceInterface $data, array $rules): IntegrityScanner
    {
        return new IntegrityScanner(new IntegrityRuleRegistry($rules), $data);
    }

    private function issue(
        string $repairability,
        IntegritySeverity $severity = IntegritySeverity::Warning,
        string $entity = 'page',
    ): IntegrityIssue {
        return new IntegrityIssue(
            IntegrityIssueCode::MISSING_SOURCE,
            $severity,
            $entity,
            'tl_page_translation',
            5,
            1,
            'de',
            $repairability,
        );
    }
}

final class StubRule implements IntegrityRuleInterface
{
    /**
     * @param list<string>         $entities
     * @param list<IntegrityIssue> $issues
     */
    public function __construct(
        private readonly string $name,
        private readonly int $priority,
        private readonly array $entities = [],
        private readonly array $issues = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getSupportedEntities(): array
    {
        return $this->entities;
    }

    public function isRepairable(): bool
    {
        return false;
    }

    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection
    {
        return new IntegrityIssueCollection($this->issues);
    }
}

final class ThrowingRule implements IntegrityRuleInterface
{
    public function getName(): string
    {
        return 'broken';
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function getSupportedEntities(): array
    {
        return [];
    }

    public function isRepairable(): bool
    {
        return false;
    }

    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection
    {
        throw new \RuntimeException('Third-party rule failure');
    }
}
