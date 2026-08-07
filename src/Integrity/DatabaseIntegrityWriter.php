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

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * The write side of the integrity subsystem.
 *
 * Only tables the bundle manages may be written, only by primary key, and only
 * with the columns a verified plan carries. Nothing is ever deleted by language,
 * alias or source id alone.
 */
final class DatabaseIntegrityWriter implements IntegrityWriterInterface
{
    /**
     * Tables this writer may touch. Source tables are deliberately absent: an
     * integrity repair never deletes source or default-language content.
     */
    private const WRITABLE_TABLES = [
        'tl_page_translation',
        'tl_article_translation',
        'tl_content_translation',
        'tl_news_translation',
        'tl_calendar_events_translation',
        'tl_faq_translation',
    ];

    /**
     * Free records live in Contao's own tables and may only be quarantined,
     * never deleted by the integrity subsystem.
     */
    private const QUARANTINE_ONLY_TABLES = ['tl_article', 'tl_content'];

    private const QUARANTINE_COLUMNS = ['published' => '', 'invisible' => '1'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function updateRecord(string $table, int $id, array $changes): bool
    {
        if (!$this->mayWrite($table) || $id <= 0 || [] === $changes) {
            return false;
        }

        try {
            $columns = $this->columns($table);
            $data = array_intersect_key($changes, array_flip($columns));

            if ([] === $data) {
                return false;
            }

            $this->connection->update($table, $data, ['id' => $id]);

            return true;
        } catch (\Throwable $exception) {
            $this->log($exception);

            return false;
        }
    }

    public function quarantineRecord(string $table, int $id): bool
    {
        if ($id <= 0 || (!$this->mayWrite($table) && !in_array($table, self::QUARANTINE_ONLY_TABLES, true))) {
            return false;
        }

        try {
            $columns = $this->columns($table);
            $data = array_intersect_key(self::QUARANTINE_COLUMNS, array_flip($columns));

            if ([] === $data) {
                // Without an inactive marker the record cannot be quarantined;
                // reporting stays the safe outcome.
                return false;
            }

            $this->connection->update($table, $data, ['id' => $id]);

            return true;
        } catch (\Throwable $exception) {
            $this->log($exception);

            return false;
        }
    }

    public function deleteRecord(string $table, int $id): bool
    {
        // Only bundle-managed translation records may ever be deleted.
        if (!in_array($table, self::WRITABLE_TABLES, true) || $id <= 0) {
            return false;
        }

        try {
            $this->connection->delete($table, ['id' => $id]);

            return true;
        } catch (\Throwable $exception) {
            $this->log($exception);

            return false;
        }
    }

    public function beginTransaction(): bool
    {
        try {
            $this->connection->beginTransaction();

            return true;
        } catch (\Throwable $exception) {
            $this->log($exception);

            return false;
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

    public function supportsTransactions(): bool
    {
        try {
            return $this->connection->getDatabasePlatform()->supportsTransactions() ?? true;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        try {
            return array_values(array_keys($this->connection->createSchemaManager()->listTableColumns($table)));
        } catch (\Throwable) {
            return [];
        }
    }

    private function mayWrite(string $table): bool
    {
        return in_array($table, self::WRITABLE_TABLES, true);
    }

    private function log(\Throwable $exception): void
    {
        $this->logger?->error('Contao Multilingual Pagetree: integrity writer error: '.$exception->getMessage());
    }
}
