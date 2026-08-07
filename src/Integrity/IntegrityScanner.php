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

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity;

use Psr\Log\LoggerInterface;

/**
 * Runs the registered rules over one scope.
 *
 * Scanning is strictly read-only: it never writes a record, never creates a
 * version and never invalidates a cache. A rule that throws is reported as a
 * rule failure and the remaining rules still run.
 */
class IntegrityScanner
{
    public function __construct(
        private readonly IntegrityRuleRegistry $rules,
        private readonly IntegrityDataSourceInterface $data,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function scan(IntegrityScope $scope): IntegrityReport
    {
        $started = microtime(true);
        $issues = new IntegrityIssueCollection();
        $executed = [];
        $failed = [];

        foreach ($this->rules->forScope($scope) as $rule) {
            $name = $rule->getName();

            try {
                $result = $rule->scan($scope, $this->data);
                $executed[] = $name;

                if ($result instanceof IntegrityIssueCollection) {
                    $issues = $issues->merge($result);
                }
            } catch (\Throwable $exception) {
                // One broken rule never aborts the scan.
                $failed[] = $name;
                $this->logger?->error(sprintf(
                    'Contao Multilingual Pagetree: integrity rule "%s" failed: %s',
                    $name,
                    $exception->getMessage(),
                ));

                $issues = $issues->with(new IntegrityIssue(
                    IntegrityIssueCode::RULE_FAILURE,
                    IntegritySeverity::Warning,
                    'integrity',
                    '',
                    0,
                    $scope->rootPageId,
                    (string) ($scope->language ?? ''),
                    IntegrityIssue::REPAIR_NONE,
                    false,
                    null,
                    null,
                    ['rule' => $name],
                ));
            }
        }

        $report = new IntegrityReport(
            $scope,
            $issues,
            $executed,
            $failed,
            (int) round((microtime(true) - $started) * 1000),
        );

        $this->logger?->info(sprintf(
            'Contao Multilingual Pagetree: integrity scan finished (scope "%s", root %d, %d issues, %d rules, %d failures).',
            $scope->type,
            $scope->rootPageId,
            $issues->count(),
            count($executed),
            count($failed),
        ));

        return $report;
    }
}
