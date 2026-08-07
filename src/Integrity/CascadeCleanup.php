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
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Safe cascading cleanup for deleted sources, deleted roots and removed
 * languages.
 *
 * Every cascade is constrained by entity table plus source id, or by root site
 * plus language. Nothing is ever removed by language code alone, by alias
 * matching or by guessing a relation, and source, default-language and
 * free-content records are never deleted here.
 */
final class CascadeCleanup
{
    /**
     * Removing a language configuration keeps its data: the records simply stop
     * rendering until the configuration is restored.
     */
    public const POLICY_RETAIN_AND_DISABLE = 'retain_and_disable';

    /** Explicit, confirmed removal of all data of one language of one root. */
    public const POLICY_DELETE_ALL = 'delete_all';

    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly IntegrityDataSourceInterface $data,
        private readonly IntegrityWriterInterface $writer,
        private readonly CapabilityPolicy $capabilities,
        private readonly ?IntegrityCacheInvalidatorInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The connected translations that depend on one deleted source record.
     *
     * Only the direct translations of that one record are included: free content
     * and unrelated records are never part of a source cascade.
     */
    public function planForSourceRecord(string $sourceTable, int $sourceId, int $rootPageId = 0): CascadePlan
    {
        $policy = $this->fields->getPolicy($sourceTable);

        if ('' === $policy->translationTable || $sourceId <= 0) {
            return new CascadePlan([], ['tl_article', 'tl_content'], $rootPageId);
        }

        $ids = [];

        foreach ($this->data->translations($policy->translationTable, IntegrityScope::installation()) as $record) {
            if ((int) ($record['pid'] ?? 0) === $sourceId) {
                $ids[] = (int) ($record['id'] ?? 0);
            }
        }

        return new CascadePlan(
            [] === $ids ? [] : [$policy->translationTable => $ids],
            ['tl_article', 'tl_content'],
            $rootPageId,
        );
    }

    /**
     * All bundle translation records of one root site, for a deleted root page.
     *
     * Free articles and content elements belong to Contao's own tables and are
     * deleted by Contao's own page cascade, so they are listed as retained here.
     */
    public function planForRoot(int $rootPageId): CascadePlan
    {
        if ($rootPageId <= 0) {
            return new CascadePlan();
        }

        $scope = IntegrityScope::root($rootPageId);
        $records = [];

        foreach ($this->fields->policies() as $policy) {
            if ('' === $policy->translationTable) {
                continue;
            }

            $ids = [];

            foreach ($this->data->translations($policy->translationTable, $scope) as $record) {
                $ids[] = (int) ($record['id'] ?? 0);
            }

            if ([] !== $ids) {
                $records[$policy->translationTable] = $ids;
            }
        }

        return new CascadePlan($records, ['tl_article', 'tl_content', 'tl_page'], $rootPageId);
    }

    /**
     * All translation records of one language of one root site.
     *
     * This plan is only ever executed through the explicit, confirmed
     * "delete language and all related data" action.
     */
    public function planForLanguage(int $rootPageId, string $language): CascadePlan
    {
        $language = trim($language);

        if ($rootPageId <= 0 || '' === $language) {
            return new CascadePlan();
        }

        $scope = IntegrityScope::root($rootPageId, $language);
        $records = [];

        foreach ($this->fields->policies() as $policy) {
            if ('' === $policy->translationTable) {
                continue;
            }

            $ids = [];

            foreach ($this->data->translations($policy->translationTable, $scope) as $record) {
                if ($scope->coversLanguage((string) ($record['language'] ?? ''))) {
                    $ids[] = (int) ($record['id'] ?? 0);
                }
            }

            if ([] !== $ids) {
                $records[$policy->translationTable] = $ids;
            }
        }

        return new CascadePlan($records, ['tl_article', 'tl_content'], $rootPageId, $language);
    }

    /**
     * Executes a cascade plan transactionally.
     *
     * @param bool $confirmed Explicit confirmation for a destructive cascade
     */
    public function execute(CascadePlan $plan, bool $confirmed): IntegrityRepairResult
    {
        // Planning a cascade is free; executing the deletions is not. This gate
        // sits at the write boundary, independently of the repair executor.
        if (!$this->capabilities->allows(Capability::IntegrityRepair)) {
            return new IntegrityRepairResult(IntegrityRepairResult::STATUS_DENIED);
        }

        if ($plan->isEmpty()) {
            return new IntegrityRepairResult(IntegrityRepairResult::STATUS_NOTHING_TO_DO);
        }

        if (!$confirmed) {
            return new IntegrityRepairResult(IntegrityRepairResult::STATUS_DENIED);
        }

        $transactional = $this->writer->supportsTransactions() && $this->writer->beginTransaction();
        $deleted = 0;
        $failed = [];

        foreach ($plan->records as $table => $ids) {
            foreach ($ids as $id) {
                if ($this->writer->deleteRecord((string) $table, (int) $id)) {
                    ++$deleted;

                    continue;
                }

                $failed[] = (string) $table;

                if ($transactional) {
                    $this->writer->rollBack();
                    $this->logger?->error('Contao Multilingual Pagetree: cascade cleanup rolled back after a failed delete.');

                    return new IntegrityRepairResult(IntegrityRepairResult::STATUS_ROLLED_BACK, 0, 0, 0, array_values(array_unique($failed)));
                }
            }
        }

        if ($transactional) {
            $this->writer->commit();
        }

        $cacheInvalidated = false;

        if ($deleted > 0 && null !== $this->cache) {
            try {
                $this->cache->invalidateRoot($plan->rootPageId);
                $cacheInvalidated = true;
            } catch (\Throwable $exception) {
                $this->logger?->error('Contao Multilingual Pagetree: cascade cache invalidation failed: '.$exception->getMessage());
            }
        }

        $this->logger?->info(sprintf(
            'Contao Multilingual Pagetree: cascade cleanup removed %d records (root %d, language "%s").',
            $deleted,
            $plan->rootPageId,
            $plan->language,
        ));

        return new IntegrityRepairResult(
            [] === $failed ? IntegrityRepairResult::STATUS_COMPLETED : IntegrityRepairResult::STATUS_PARTIAL,
            $deleted,
            0,
            0,
            array_values(array_unique($failed)),
            $cacheInvalidated,
        );
    }
}
