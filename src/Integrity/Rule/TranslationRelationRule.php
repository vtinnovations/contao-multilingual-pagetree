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

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity\Rule;

use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityDataSourceInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssue;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCode;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegritySeverity;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Validates the relation of every connected translation record.
 *
 * A broken relation is never repaired by matching titles or aliases: a
 * translation without a valid source is either removed with confirmation or
 * quarantined, never re-attached by guessing.
 */
final class TranslationRelationRule implements IntegrityRuleInterface
{
    public function __construct(private readonly TranslationFieldRegistry $fields)
    {
    }

    public function getName(): string
    {
        return 'translation_relation';
    }

    public function getPriority(): int
    {
        return 90;
    }

    public function getSupportedEntities(): array
    {
        return ['page', 'article', 'content', 'news', 'event', 'faq'];
    }

    public function isRepairable(): bool
    {
        return true;
    }

    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection
    {
        $issues = [];

        foreach ($this->fields->policies() as $policy) {
            if ('' === $policy->translationTable || '' === $policy->sourceTable) {
                continue;
            }

            if (!$scope->coversEntity($policy->entityType) || !$data->tableExists($policy->translationTable)) {
                continue;
            }

            $issues = [...$issues, ...$this->scanTable(
                $policy->entityType,
                $policy->translationTable,
                $policy->sourceTable,
                $scope,
                $data,
            )];
        }

        return new IntegrityIssueCollection($issues);
    }

    /**
     * @return list<IntegrityIssue>
     */
    private function scanTable(
        string $entityType,
        string $translationTable,
        string $sourceTable,
        IntegrityScope $scope,
        IntegrityDataSourceInterface $data,
    ): array {
        $records = $data->translations($translationTable, $scope);

        if ([] === $records) {
            return [];
        }

        $sourceIds = [];

        foreach ($records as $record) {
            $sourceId = (int) ($record['pid'] ?? 0);

            if ($sourceId > 0) {
                $sourceIds[$sourceId] = $sourceId;
            }
        }

        // One batched lookup instead of one query per translation.
        $sources = $data->sourceRecords($sourceTable, array_values($sourceIds));
        $issues = [];
        $seen = [];

        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            $sourceId = (int) ($record['pid'] ?? 0);
            $language = (string) ($record['language'] ?? '');
            $rootPageId = $scope->rootPageId;

            if (!$scope->coversLanguage($language)) {
                continue;
            }

            if ($sourceId <= 0 || !isset($sources[$sourceId])) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::MISSING_SOURCE,
                    IntegritySeverity::Error,
                    $entityType,
                    $translationTable,
                    $id,
                    $rootPageId,
                    $language,
                    $sourceTable,
                    $sourceId,
                    IntegrityIssue::REPAIR_CONFIRMATION,
                    true,
                );

                continue;
            }

            // A translation may never point at itself or at another translation.
            if ($translationTable === $sourceTable && $sourceId === $id) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::SELF_REFERENTIAL_SOURCE,
                    IntegritySeverity::Critical,
                    $entityType,
                    $translationTable,
                    $id,
                    $rootPageId,
                    $language,
                    $sourceTable,
                    $sourceId,
                    IntegrityIssue::REPAIR_CONFIRMATION,
                );

                continue;
            }

            $source = $sources[$sourceId];

            if (array_key_exists('language', $source) && array_key_exists('fieldStates', $source)) {
                // The referenced record is itself a translation record.
                $issues[] = $this->issue(
                    IntegrityIssueCode::TRANSLATION_SOURCE_RELATION,
                    IntegritySeverity::Critical,
                    $entityType,
                    $translationTable,
                    $id,
                    $rootPageId,
                    $language,
                    $sourceTable,
                    $sourceId,
                    IntegrityIssue::REPAIR_CONFIRMATION,
                );

                continue;
            }

            $sourceRoot = $data->rootPageIdOfSource($sourceTable, $sourceId);

            if ($sourceRoot > 0 && $rootPageId > 0 && $sourceRoot !== $rootPageId) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::CROSS_SITE_RELATION,
                    IntegritySeverity::Critical,
                    $entityType,
                    $translationTable,
                    $id,
                    $rootPageId,
                    $language,
                    $sourceTable,
                    $sourceId,
                    IntegrityIssue::REPAIR_CONFIRMATION,
                );

                continue;
            }

            $key = $sourceId.'|'.strtolower(str_replace('-', '_', $language));

            if (isset($seen[$key])) {
                $issues[] = $this->duplicateIssue(
                    $entityType,
                    $translationTable,
                    $id,
                    $rootPageId,
                    $language,
                    $sourceTable,
                    $sourceId,
                    $record,
                    $seen[$key],
                );

                continue;
            }

            $seen[$key] = $id;
        }

        return $issues;
    }

    /**
     * A duplicate may only be classified as redundant when it provably carries
     * no unique information.
     *
     * @param array<string, mixed> $record
     */
    private function duplicateIssue(
        string $entityType,
        string $translationTable,
        int $id,
        int $rootPageId,
        string $language,
        string $sourceTable,
        int $sourceId,
        array $record,
        int $keptId,
    ): IntegrityIssue {
        $redundant = $this->isProvablyRedundant($record);

        return new IntegrityIssue(
            IntegrityIssueCode::DUPLICATE_TRANSLATION,
            IntegritySeverity::Error,
            $entityType,
            $translationTable,
            $id,
            $rootPageId,
            $language,
            $redundant ? IntegrityIssue::REPAIR_CONFIRMATION : IntegrityIssue::REPAIR_MANUAL,
            $redundant,
            $sourceTable,
            $sourceId,
            [
                'redundant' => $redundant,
                'keeps' => $keptId,
                'published' => (string) ($record['published'] ?? ''),
                'reviewStatus' => (string) ($record['reviewStatus'] ?? ''),
                'hasAlias' => '' !== (string) ($record['alias'] ?? ''),
            ],
            [$keptId],
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isProvablyRedundant(array $record): bool
    {
        // A unique alias, a custom or deliberately empty field state, an own
        // publication state or a review baseline all make the record unique.
        if ('' !== trim((string) ($record['alias'] ?? ''))) {
            return false;
        }

        if ('' !== trim((string) ($record['reviewedSourceRevision'] ?? ''))) {
            return false;
        }

        if ('' !== trim((string) ($record['published'] ?? ''))) {
            return false;
        }

        $states = $record['fieldStates'] ?? null;

        if (is_string($states) && '' !== trim($states)) {
            try {
                $decoded = json_decode($states, true, 8, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return false;
            }

            if (is_array($decoded)) {
                foreach ($decoded as $state) {
                    if (in_array($state, ['custom', 'empty'], true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function issue(
        string $code,
        IntegritySeverity $severity,
        string $entityType,
        string $table,
        int $id,
        int $rootPageId,
        string $language,
        string $sourceTable,
        int $sourceId,
        string $repairability,
        bool $destructive = false,
    ): IntegrityIssue {
        return new IntegrityIssue(
            $code,
            $severity,
            $entityType,
            $table,
            $id,
            $rootPageId,
            $language,
            $repairability,
            $destructive,
            $sourceTable,
            $sourceId,
        );
    }
}
