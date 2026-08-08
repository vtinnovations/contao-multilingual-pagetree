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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewStorageInterface;

/**
 * In-memory review storage that also counts queries, so tests can assert that
 * list rendering does not degrade into one query per row.
 */
class InMemoryReviewStorage implements TranslationReviewStorageInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];

    public int $sourceReads = 0;
    public int $translationReads = 0;
    public int $statusRefreshes = 0;

    /**
     * @param array<string, array<int, array<string, mixed>>> $tables
     */
    public function __construct(array $tables = [])
    {
        $this->tables = $tables;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function put(string $table, array $row): self
    {
        $this->tables[$table][(int) ($row['id'] ?? 0)] = $row;

        return $this;
    }

    public function findTranslation(string $translationTable, int $id): ?array
    {
        ++$this->translationReads;

        return $this->tables[$translationTable][$id] ?? null;
    }

    public function findSource(string $sourceTable, int $id): ?array
    {
        ++$this->sourceReads;

        return $this->tables[$sourceTable][$id] ?? null;
    }

    public function findTranslationsOfSource(string $translationTable, int $sourceId): array
    {
        ++$this->translationReads;
        $result = [];

        foreach ($this->tables[$translationTable] ?? [] as $id => $row) {
            if ((int) ($row['pid'] ?? 0) === $sourceId) {
                $result[$id] = $row;
            }
        }

        return $result;
    }

    public function saveReviewData(string $translationTable, int $id, array $reviewData): void
    {
        if (!isset($this->tables[$translationTable][$id])) {
            return;
        }

        foreach ($reviewData as $field => $value) {
            $this->tables[$translationTable][$id][$field] = $value;
        }
    }

    public function refreshStatuses(string $translationTable, int $sourceId, string $currentRevision): void
    {
        ++$this->statusRefreshes;

        foreach ($this->tables[$translationTable] ?? [] as $id => $row) {
            if ((int) ($row['pid'] ?? 0) !== $sourceId) {
                continue;
            }

            $revision = $row[TranslationReviewResolver::FIELD_REVISION] ?? '';
            $status = ReviewStatus::Unreviewed;

            if (is_string($revision) && '' !== $revision) {
                $status = $revision === $currentRevision ? ReviewStatus::UpToDate : ReviewStatus::NeedsReview;
            }

            $this->tables[$translationTable][$id][TranslationReviewResolver::FIELD_STATUS] = $status->value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function row(string $table, int $id): array
    {
        return $this->tables[$table][$id] ?? [];
    }
}
