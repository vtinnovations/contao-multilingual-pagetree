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
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;

/**
 * Read-only Doctrine access for integrity rules.
 *
 * Table names always come from the field-policy registry or from fixed
 * constants, never from request input, and are validated again here. Every
 * translation and free-record query is constrained by the scanned root site.
 */
final class DatabaseIntegrityDataSource implements IntegrityDataSourceInterface
{
    /** @var array<string, bool> */
    private array $tableCache = [];

    /** @var array<string, int> */
    private array $rootCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function rootPageIds(IntegrityScope $scope): array
    {
        if (!$scope->isInstallationWide()) {
            return $scope->rootPageId > 0 ? [$scope->rootPageId] : [];
        }

        try {
            $rows = $this->connection->fetchFirstColumn("SELECT id FROM tl_page WHERE type = 'root' ORDER BY id ASC");
        } catch (\Throwable $exception) {
            $this->log($exception);

            return [];
        }

        return array_map('intval', $rows);
    }

    public function languageConfigurations(int $rootPageId): array
    {
        if ($rootPageId <= 0 || !$this->tableExists('tl_inline_language')) {
            return [];
        }

        try {
            return $this->connection->fetchAllAssociative(
                'SELECT * FROM tl_inline_language WHERE pid = :pid ORDER BY sorting ASC, id ASC',
                ['pid' => $rootPageId],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return [];
        }
    }

    public function translations(string $translationTable, IntegrityScope $scope): array
    {
        if (!$this->isSafeTable($translationTable) || !$this->tableExists($translationTable)) {
            return [];
        }

        $sourceTable = substr($translationTable, 0, -strlen('_translation'));

        try {
            // Installation-wide scans read everything; a scoped scan is joined to
            // the root site so another site's records are never returned.
            if ($scope->isInstallationWide() || $scope->rootPageId <= 0) {
                $rows = $this->connection->fetchAllAssociative(
                    sprintf('SELECT * FROM %s ORDER BY id ASC', $translationTable),
                );
            } else {
                $rows = $this->connection->fetchAllAssociative(
                    sprintf(
                        'SELECT t.* FROM %s t WHERE t.pid IN (SELECT s.id FROM %s s) ORDER BY t.id ASC',
                        $translationTable,
                        $sourceTable,
                    ),
                );
                $rows = $this->filterByRoot($rows, $sourceTable, $scope->rootPageId);
            }
        } catch (\Throwable $exception) {
            $this->log($exception);

            return [];
        }

        if (null !== $scope->language) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $scope->coversLanguage((string) ($row['language'] ?? '')),
            ));
        }

        return $rows;
    }

    public function sourceRecords(string $sourceTable, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ([] === $ids || !$this->isSafeTable($sourceTable) || !$this->tableExists($sourceTable)) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE id IN (:ids)', $sourceTable),
                ['ids' => $ids],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
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

    public function rootPageIdOfSource(string $sourceTable, int $sourceId): int
    {
        if ($sourceId <= 0 || !$this->isSafeTable($sourceTable)) {
            return 0;
        }

        $key = $sourceTable.'|'.$sourceId;

        if (isset($this->rootCache[$key])) {
            return $this->rootCache[$key];
        }

        try {
            $pageId = match ($sourceTable) {
                'tl_page' => $sourceId,
                'tl_article' => (int) $this->connection->fetchOne('SELECT pid FROM tl_article WHERE id = :id', ['id' => $sourceId]),
                default => 0,
            };

            $root = $pageId > 0 ? $this->resolvePageRoot($pageId) : 0;
        } catch (\Throwable $exception) {
            $this->log($exception);

            $root = 0;
        }

        return $this->rootCache[$key] = $root;
    }

    public function freeRecords(string $table, IntegrityScope $scope): array
    {
        if (!$this->isSafeTable($table) || !$this->tableExists($table)) {
            return [];
        }

        $language = ContentOwnership::FIELD_LANGUAGE;
        $root = ContentOwnership::FIELD_ROOT;

        try {
            if ($scope->isInstallationWide() || $scope->rootPageId <= 0) {
                return $this->connection->fetchAllAssociative(
                    sprintf('SELECT * FROM %s WHERE %s != :empty ORDER BY id ASC', $table, $language),
                    ['empty' => ''],
                );
            }

            return $this->connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE %s != :empty AND %s = :root ORDER BY id ASC', $table, $language, $root),
                ['empty' => '', 'root' => $scope->rootPageId],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return [];
        }
    }

    public function record(string $table, int $id): ?array
    {
        if (!$this->isSafeTable($table) || $id <= 0 || !$this->tableExists($table)) {
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

    public function tableExists(string $table): bool
    {
        if (!$this->isSafeTable($table)) {
            return false;
        }

        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }

        try {
            $exists = $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            $exists = false;
        }

        return $this->tableCache[$table] = $exists;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function filterByRoot(array $rows, string $sourceTable, int $rootPageId): array
    {
        return array_values(array_filter($rows, function (array $row) use ($sourceTable, $rootPageId): bool {
            $sourceRoot = $this->rootPageIdOfSource($sourceTable, (int) ($row['pid'] ?? 0));

            // Records whose root cannot be determined stay visible so the
            // relation rules can report them instead of hiding them.
            return 0 === $sourceRoot || $sourceRoot === $rootPageId;
        }));
    }

    private function resolvePageRoot(int $pageId): int
    {
        $seen = [];
        $current = $pageId;

        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;

            $row = $this->connection->fetchAssociative('SELECT id, pid, type FROM tl_page WHERE id = :id', ['id' => $current]);

            if (!is_array($row)) {
                return 0;
            }

            if ('root' === (string) ($row['type'] ?? '')) {
                return (int) $row['id'];
            }

            $current = (int) ($row['pid'] ?? 0);
        }

        return 0;
    }

    private function isSafeTable(string $table): bool
    {
        return 1 === preg_match('/^[a-z0-9_]+$/', $table);
    }

    private function log(\Throwable $exception): void
    {
        $this->logger?->error('Contao Multilingual Pagetree: integrity data source error: '.$exception->getMessage());
    }
}
