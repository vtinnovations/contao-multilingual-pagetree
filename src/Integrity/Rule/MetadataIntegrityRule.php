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
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Validates the field-state and review metadata of connected translations.
 *
 * Both kinds of metadata are safely normalisable: invalid states become
 * "inherit", unsupported entries are dropped and an unusable review baseline
 * becomes "unreviewed". Translated field values are never deleted and a record
 * is never marked reviewed.
 */
final class MetadataIntegrityRule implements IntegrityRuleInterface
{
    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly FieldStateMap $states,
    ) {
    }

    public function getName(): string
    {
        return 'translation_metadata';
    }

    public function getPriority(): int
    {
        return 80;
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
            if ('' === $policy->translationTable || !$scope->coversEntity($policy->entityType)) {
                continue;
            }

            if (!$data->tableExists($policy->translationTable)) {
                continue;
            }

            $supported = array_keys($policy->fields());

            foreach ($data->translations($policy->translationTable, $scope) as $record) {
                $language = (string) ($record['language'] ?? '');

                if (!$scope->coversLanguage($language)) {
                    continue;
                }

                $id = (int) ($record['id'] ?? 0);

                $fieldStateIssue = $this->checkFieldStates($record, $supported, $policy->entityType, $policy->translationTable, $id, $scope->rootPageId, $language);

                if (null !== $fieldStateIssue) {
                    $issues[] = $fieldStateIssue;
                }

                $reviewIssue = $this->checkReviewMetadata($record, $policy->entityType, $policy->translationTable, $id, $scope->rootPageId, $language);

                if (null !== $reviewIssue) {
                    $issues[] = $reviewIssue;
                }
            }
        }

        return new IntegrityIssueCollection($issues);
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string>         $supported
     */
    private function checkFieldStates(
        array $record,
        array $supported,
        string $entityType,
        string $table,
        int $id,
        int $rootPageId,
        string $language,
    ): ?IntegrityIssue {
        if (!array_key_exists('fieldStates', $record)) {
            return null;
        }

        $raw = $record['fieldStates'];

        if (null === $raw || (is_string($raw) && '' === trim($raw))) {
            return null;
        }

        $malformed = false;
        $decoded = [];

        if (is_string($raw)) {
            try {
                $json = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                $decoded = is_array($json) ? $json : [];
                $malformed = !is_array($json);
            } catch (\Throwable) {
                $malformed = true;
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $malformed = true;
        }

        $unsupported = [];
        $invalidStates = [];

        foreach ($decoded as $field => $state) {
            if (!is_string($field) || !in_array($field, $supported, true)) {
                $unsupported[] = is_string($field) ? $field : '?';

                continue;
            }

            if (!in_array($state, [FieldStateMap::INHERIT, FieldStateMap::CUSTOM, FieldStateMap::EMPTY], true)) {
                $invalidStates[] = $field;
            }
        }

        if (!$malformed && [] === $unsupported && [] === $invalidStates) {
            return null;
        }

        // The normalised map keeps every supported field and drops the rest;
        // translated values themselves are never touched.
        $normalised = $this->states->encode($this->states->normalize($malformed ? [] : $decoded, $supported));

        return new IntegrityIssue(
            IntegrityIssueCode::INVALID_FIELD_STATES,
            $malformed ? IntegritySeverity::Warning : IntegritySeverity::Info,
            $entityType,
            $table,
            $id,
            $rootPageId,
            $language,
            IntegrityIssue::REPAIR_AUTOMATIC,
            false,
            null,
            null,
            [
                'malformed' => $malformed,
                'unsupported' => count($unsupported),
                'invalidStates' => count($invalidStates),
                'normalised' => $normalised,
            ],
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private function checkReviewMetadata(
        array $record,
        string $entityType,
        string $table,
        int $id,
        int $rootPageId,
        string $language,
    ): ?IntegrityIssue {
        if (!array_key_exists(TranslationReviewResolver::FIELD_STATUS, $record)) {
            return null;
        }

        $status = $record[TranslationReviewResolver::FIELD_STATUS] ?? null;
        $revision = $record[TranslationReviewResolver::FIELD_REVISION] ?? null;
        $snapshot = $record[TranslationReviewResolver::FIELD_SNAPSHOT] ?? null;

        $invalidStatus = !is_string($status) || null === ReviewStatus::tryFrom($status);
        $hasRevision = is_string($revision) && '' !== trim($revision);
        $invalidRevision = $hasRevision && 1 !== preg_match('/^[a-f0-9]{64}$/i', trim($revision));
        $invalidSnapshot = false;

        if (is_string($snapshot) && '' !== trim($snapshot)) {
            try {
                $invalidSnapshot = !is_array(json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                $invalidSnapshot = true;
            }
        }

        // A status that claims a review without a usable baseline is impossible.
        $impossibleStatus = !$invalidStatus
            && ReviewStatus::Unreviewed->value !== $status
            && (!$hasRevision || $invalidRevision);

        if (!$invalidStatus && !$invalidRevision && !$invalidSnapshot && !$impossibleStatus) {
            return null;
        }

        return new IntegrityIssue(
            IntegrityIssueCode::INVALID_REVIEW_METADATA,
            IntegritySeverity::Warning,
            $entityType,
            $table,
            $id,
            $rootPageId,
            $language,
            IntegrityIssue::REPAIR_AUTOMATIC,
            false,
            null,
            null,
            [
                'invalidStatus' => $invalidStatus,
                'invalidRevision' => $invalidRevision,
                'invalidSnapshot' => $invalidSnapshot,
                'impossibleStatus' => $impossibleStatus,
            ],
        );
    }
}
