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

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Doctrine backed review storage.
 *
 * Table names never come from request input: they are always resolved from the
 * point 7 policy registry before they reach this class, and are validated again
 * here as a defence in depth.
 */
final class DatabaseTranslationReviewStorage implements TranslationReviewStorageInterface
{
    /** @var array<string, bool> */
    private array $tableSupport = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function findTranslation(string $translationTable, int $id): ?array
    {
        return $this->findRow($translationTable, $id);
    }

    public function findSource(string $sourceTable, int $id): ?array
    {
        return $this->findRow($sourceTable, $id);
    }

    public function findTranslationsOfSource(string $translationTable, int $sourceId): array
    {
        if (!$this->isSafeTable($translationTable) || $sourceId <= 0) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE pid = :pid', $translationTable),
                ['pid' => $sourceId],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $result[(int) ($row['id'] ?? 0)] = $row;
        }

        return $result;
    }

    public function saveReviewData(string $translationTable, int $id, array $reviewData): void
    {
        if (!$this->isSafeTable($translationTable) || $id <= 0 || [] === $reviewData) {
            return;
        }

        $allowed = [
            TranslationReviewResolver::FIELD_STATUS,
            TranslationReviewResolver::FIELD_REVISION,
            TranslationReviewResolver::FIELD_SNAPSHOT,
            TranslationReviewResolver::FIELD_REVIEWED_AT,
            TranslationReviewResolver::FIELD_REVIEWED_BY,
        ];

        // Only review metadata may ever be written from here.
        $data = array_intersect_key($reviewData, array_flip($allowed));

        if ([] === $data || !$this->supportsReviewColumns($translationTable)) {
            return;
        }

        try {
            $this->connection->update($translationTable, $data, ['id' => $id]);
        } catch (\Throwable $exception) {
            $this->log($exception);
        }
    }

    public function refreshStatuses(string $translationTable, int $sourceId, string $currentRevision): void
    {
        if (!$this->isSafeTable($translationTable) || $sourceId <= 0 || !$this->supportsReviewColumns($translationTable)) {
            return;
        }

        $status = TranslationReviewResolver::FIELD_STATUS;
        $revision = TranslationReviewResolver::FIELD_REVISION;

        try {
            // Three bounded, index friendly statements instead of one query per
            // translation row.
            $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s SET %s = :status WHERE pid = :pid AND (%s IS NULL OR %s = :empty)',
                    $translationTable,
                    $status,
                    $revision,
                    $revision,
                ),
                ['status' => ReviewStatus::Unreviewed->value, 'pid' => $sourceId, 'empty' => ''],
            );

            if ('' === $currentRevision) {
                return;
            }

            $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s SET %s = :status WHERE pid = :pid AND %s = :revision',
                    $translationTable,
                    $status,
                    $revision,
                ),
                ['status' => ReviewStatus::UpToDate->value, 'pid' => $sourceId, 'revision' => $currentRevision],
            );

            $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s SET %s = :status WHERE pid = :pid AND %s IS NOT NULL AND %s != :empty AND %s != :revision',
                    $translationTable,
                    $status,
                    $revision,
                    $revision,
                    $revision,
                ),
                [
                    'status' => ReviewStatus::NeedsReview->value,
                    'pid' => $sourceId,
                    'empty' => '',
                    'revision' => $currentRevision,
                ],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $table, int $id): ?array
    {
        if (!$this->isSafeTable($table) || $id <= 0) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                sprintf('SELECT * FROM %s WHERE id = :id', $table),
                ['id' => $id],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return null;
        }

        return is_array($row) ? $row : null;
    }

    private function supportsReviewColumns(string $table): bool
    {
        if (isset($this->tableSupport[$table])) {
            return $this->tableSupport[$table];
        }

        try {
            $columns = $this->connection->createSchemaManager()->listTableColumns($table);
            $supported = isset($columns[strtolower(TranslationReviewResolver::FIELD_STATUS)]);
        } catch (\Throwable) {
            $supported = false;
        }

        return $this->tableSupport[$table] = $supported;
    }

    private function isSafeTable(string $table): bool
    {
        return 1 === preg_match('/^[a-z0-9_]+$/', $table);
    }

    private function log(\Throwable $exception): void
    {
        $this->logger?->error('Contao Multilingual Pagetree: review storage error: '.$exception->getMessage());
    }
}
