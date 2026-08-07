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

/**
 * The read-only result of one scan.
 */
final class IntegrityReport
{
    /**
     * @param list<string> $executedRules
     * @param list<string> $failedRules
     */
    public function __construct(
        public readonly IntegrityScope $scope,
        public readonly IntegrityIssueCollection $issues,
        public readonly array $executedRules = [],
        public readonly array $failedRules = [],
        public readonly int $durationMs = 0,
    ) {
    }

    public function hasIssues(): bool
    {
        return !$this->issues->isEmpty();
    }

    public function hasBlockingIssues(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity->blocksExitCode()) {
                return true;
            }
        }

        return false;
    }

    /**
     * CLI exit code: 0 clean, 1 repairable/warnings, 2 errors or critical.
     */
    public function exitCode(): int
    {
        if ($this->hasBlockingIssues()) {
            return 2;
        }

        return $this->hasIssues() ? 1 : 0;
    }

    /**
     * Structured output without translated content or internal details.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->toArray(),
            'total' => $this->issues->count(),
            'severities' => $this->issues->countsBySeverity(),
            'entities' => $this->issues->countsByEntity(),
            'rules' => ['executed' => $this->executedRules, 'failed' => $this->failedRules],
            'durationMs' => $this->durationMs,
            'issues' => $this->issues->toArray(),
        ];
    }
}
