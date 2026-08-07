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
use Vtinnovations\ContaoMultilingualPagetree\Security\Capability;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;

/**
 * Executes a verified repair plan.
 *
 * The plan is revalidated against a fresh scan immediately before execution, so
 * a stale plan is rejected. Execution runs inside a transaction where the
 * database supports one and reports partial completion precisely otherwise.
 */
final class IntegrityRepairExecutor
{
    public function __construct(
        private readonly IntegrityScanner $scanner,
        private readonly IntegrityRepairPlanner $planner,
        private readonly IntegrityWriterInterface $writer,
        private readonly CapabilityPolicy $capabilities,
        private readonly ?IntegrityCacheInvalidatorInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param bool $confirmed The editor explicitly confirmed destructive actions
     */
    public function execute(IntegrityRepairPlan $plan, bool $confirmed): IntegrityRepairResult
    {
        // Scanning and reporting stay available to every installation; only the
        // writing repair is a licensed capability, and it is checked here rather
        // than in the caller that happens to invoke it.
        if (!$this->capabilities->allows(Capability::IntegrityRepair)) {
            return new IntegrityRepairResult(IntegrityRepairResult::STATUS_DENIED);
        }

        if ($plan->isEmpty()) {
            return new IntegrityRepairResult(IntegrityRepairResult::STATUS_NOTHING_TO_DO);
        }

        if ($plan->hasDestructiveActions() && !$confirmed) {
            return new IntegrityRepairResult(IntegrityRepairResult::STATUS_DENIED);
        }

        // Revalidate: the data may have changed since the preview.
        if ($this->planner->isStale($plan, $this->scanner->scan($plan->scope))) {
            $this->logger?->warning('Contao Multilingual Pagetree: a stale integrity repair plan was rejected.');

            return new IntegrityRepairResult(IntegrityRepairResult::STATUS_STALE_PLAN);
        }

        $transactional = $this->writer->supportsTransactions() && $this->writer->beginTransaction();

        $deleted = 0;
        $quarantined = 0;
        $normalised = 0;
        $failed = [];

        foreach ($plan->orderedActions() as $action) {
            try {
                $applied = match ($action->type) {
                    IntegrityRepairAction::TYPE_NORMALISE => $this->writer->updateRecord($action->table, $action->recordId, $action->changes),
                    IntegrityRepairAction::TYPE_QUARANTINE => $this->writer->quarantineRecord($action->table, $action->recordId),
                    IntegrityRepairAction::TYPE_DELETE => $this->writer->deleteRecord($action->table, $action->recordId),
                    default => false,
                };
            } catch (\Throwable $exception) {
                $applied = false;
                $this->logger?->error('Contao Multilingual Pagetree: integrity repair action failed: '.$exception->getMessage());
            }

            if (!$applied) {
                $failed[] = $action->issueCode;

                if ($transactional) {
                    $this->writer->rollBack();
                    $this->logger?->error('Contao Multilingual Pagetree: integrity repair rolled back after a failed action.');

                    return new IntegrityRepairResult(IntegrityRepairResult::STATUS_ROLLED_BACK, 0, 0, 0, $failed);
                }

                continue;
            }

            match ($action->type) {
                IntegrityRepairAction::TYPE_DELETE => ++$deleted,
                IntegrityRepairAction::TYPE_QUARANTINE => ++$quarantined,
                default => ++$normalised,
            };
        }

        if ($transactional) {
            $this->writer->commit();
        }

        $cacheInvalidated = false;

        if ($deleted + $quarantined + $normalised > 0) {
            $cacheInvalidated = $this->invalidateCaches($plan);
        }

        $status = [] === $failed
            ? IntegrityRepairResult::STATUS_COMPLETED
            : IntegrityRepairResult::STATUS_PARTIAL;

        $this->logger?->info(sprintf(
            'Contao Multilingual Pagetree: integrity repair %s (deleted %d, quarantined %d, normalised %d, failed %d).',
            $status,
            $deleted,
            $quarantined,
            $normalised,
            count($failed),
        ));

        return new IntegrityRepairResult($status, $deleted, $quarantined, $normalised, $failed, $cacheInvalidated);
    }

    private function invalidateCaches(IntegrityRepairPlan $plan): bool
    {
        if (null === $this->cache) {
            return false;
        }

        try {
            // Only the affected root site is invalidated.
            $this->cache->invalidateRoot($plan->scope->rootPageId);

            return true;
        } catch (\Throwable $exception) {
            $this->logger?->error('Contao Multilingual Pagetree: cache invalidation after a repair failed: '.$exception->getMessage());

            return false;
        }
    }
}
