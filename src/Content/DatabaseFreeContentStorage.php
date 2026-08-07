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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Doctrine backed free-content storage.
 *
 * Every query that touches free records constrains language and root site, so a
 * record of one language or one site can never be counted, read or written for
 * another.
 */
final class DatabaseFreeContentStorage implements FreeContentStorageInterface
{
    /** @var array<string, list<string>> */
    private array $columnCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function countFreeArticles(int $rootPageId, string $language): int
    {
        return $this->countFree('tl_article', $rootPageId, $language);
    }

    public function countFreeContentElements(int $rootPageId, string $language): int
    {
        return $this->countFree('tl_content', $rootPageId, $language);
    }

    public function countConnectedTranslations(string $translationTable, string $language): int
    {
        if (!$this->isSafeTable($translationTable) || '' === $language) {
            return 0;
        }

        try {
            return (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE language = :language', $translationTable),
                ['language' => $language],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return 0;
        }
    }

    public function findSourceArticles(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        try {
            return $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT * FROM tl_article WHERE pid = :pid AND (%s IS NULL OR %s = :empty) ORDER BY sorting ASC, id ASC',
                    ContentOwnership::FIELD_LANGUAGE,
                    ContentOwnership::FIELD_LANGUAGE,
                ),
                ['pid' => $pageId, 'empty' => ''],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return [];
        }
    }

    public function findChildContent(string $parentTable, int $parentId): array
    {
        if (!$this->isSafeTable($parentTable) || $parentId <= 0) {
            return [];
        }

        try {
            return $this->connection->fetchAllAssociative(
                'SELECT * FROM tl_content WHERE pid = :pid AND ptable = :ptable ORDER BY sorting ASC, id ASC',
                ['pid' => $parentId, 'ptable' => $parentTable],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return [];
        }
    }

    public function findRecord(string $table, int $id): ?array
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

    public function insertRecord(string $table, array $row): int
    {
        if (!$this->isSafeTable($table) || [] === $row) {
            return 0;
        }

        unset($row['id']);

        try {
            $this->connection->insert($table, $row);

            return (int) $this->connection->lastInsertId();
        } catch (\Throwable $exception) {
            $this->log($exception);

            throw $exception;
        }
    }

    public function columns(string $table): array
    {
        if (!$this->isSafeTable($table)) {
            return [];
        }

        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        try {
            $columns = array_keys($this->connection->createSchemaManager()->listTableColumns($table));
        } catch (\Throwable $exception) {
            $this->log($exception);

            return $this->columnCache[$table] = [];
        }

        return $this->columnCache[$table] = array_values($columns);
    }

    public function beginTransaction(): void
    {
        try {
            $this->connection->beginTransaction();
        } catch (\Throwable $exception) {
            $this->log($exception);
        }
    }

    public function commit(): void
    {
        try {
            if ($this->connection->isTransactionActive()) {
                $this->connection->commit();
            }
        } catch (\Throwable $exception) {
            $this->log($exception);
        }
    }

    public function rollBack(): void
    {
        try {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        } catch (\Throwable $exception) {
            $this->log($exception);
        }
    }

    private function countFree(string $table, int $rootPageId, string $language): int
    {
        if (!$this->isSafeTable($table) || '' === $language) {
            return 0;
        }

        try {
            return (int) $this->connection->fetchOne(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE %s = :language AND (%s = :root OR :root = 0)',
                    $table,
                    ContentOwnership::FIELD_LANGUAGE,
                    ContentOwnership::FIELD_ROOT,
                ),
                ['language' => $language, 'root' => $rootPageId],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return 0;
        }
    }

    private function isSafeTable(string $table): bool
    {
        return 1 === preg_match('/^[a-z0-9_]+$/', $table);
    }

    private function log(\Throwable $exception): void
    {
        $this->logger?->error('Contao Multilingual Pagetree: free content storage error: '.$exception->getMessage());
    }
}
