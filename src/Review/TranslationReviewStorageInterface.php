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
 * Persistence seam of the review layer.
 */
interface TranslationReviewStorageInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findTranslation(string $translationTable, int $id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findSource(string $sourceTable, int $id): ?array;

    /**
     * All translations of one source record, keyed by translation id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findTranslationsOfSource(string $translationTable, int $sourceId): array;

    /**
     * Writes review metadata only. Translated values, field states and
     * publication fields are never touched.
     *
     * @param array<string, mixed> $reviewData
     */
    public function saveReviewData(string $translationTable, int $id, array $reviewData): void;

    /**
     * Refreshes the persisted status of every translation of one source record
     * with a small, bounded number of statements.
     */
    public function refreshStatuses(string $translationTable, int $sourceId, string $currentRevision): void;
}
