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

namespace Vtinnovations\ContaoMultilingualPagetree\Review;

/**
 * The resolved review state of one translation record.
 *
 * The status is always derived from the live fingerprint comparison, so a stale
 * persisted status can never override it.
 */
final class ReviewState
{
    /**
     * @param list<ChangedSourceField> $changedFields
     */
    private function __construct(
        public readonly ReviewStatus $status,
        public readonly int $reviewedAt,
        public readonly int $reviewedBy,
        public readonly ?string $reviewedRevision,
        public readonly ?string $currentRevision,
        public readonly array $changedFields,
        public readonly bool $sourceAvailable,
    ) {
    }

    /**
     * @param list<ChangedSourceField> $changedFields
     */
    public static function create(
        ReviewStatus $status,
        int $reviewedAt = 0,
        int $reviewedBy = 0,
        ?string $reviewedRevision = null,
        ?string $currentRevision = null,
        array $changedFields = [],
        bool $sourceAvailable = true,
    ): self {
        return new self($status, $reviewedAt, $reviewedBy, $reviewedRevision, $currentRevision, $changedFields, $sourceAvailable);
    }

    public static function sourceMissing(int $reviewedAt = 0, int $reviewedBy = 0): self
    {
        return new self(ReviewStatus::SourceMissing, $reviewedAt, $reviewedBy, null, null, [], false);
    }

    public function isReviewable(): bool
    {
        return $this->sourceAvailable && ReviewStatus::SourceMissing !== $this->status;
    }

    public function needsAttention(): bool
    {
        return $this->status->needsAttention();
    }

    /**
     * @return list<string>
     */
    public function changedFieldNames(): array
    {
        return array_map(static fn (ChangedSourceField $field): string => $field->field, $this->changedFields);
    }
}
