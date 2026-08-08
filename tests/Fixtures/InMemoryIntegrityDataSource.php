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

use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityDataSourceInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;

/**
 * In-memory, read-only data source for integrity rule tests.
 *
 * It records every write attempt so a test can prove that scanning never
 * modifies data.
 */
class InMemoryIntegrityDataSource implements IntegrityDataSourceInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];

    /** @var array<int, int> source page id => root page id */
    public array $pageRoots = [];

    public int $reads = 0;

    /**
     * @param array<string, mixed> $row
     */
    public function put(string $table, array $row): self
    {
        $this->tables[$table][(int) ($row['id'] ?? 0)] = $row;

        return $this;
    }

    public function withPageRoot(int $pageId, int $rootPageId): self
    {
        $this->pageRoots[$pageId] = $rootPageId;

        return $this;
    }

    public function rootPageIds(IntegrityScope $scope): array
    {
        if (!$scope->isInstallationWide()) {
            return $scope->rootPageId > 0 ? [$scope->rootPageId] : [];
        }

        $roots = [];

        foreach ($this->tables['tl_page'] ?? [] as $page) {
            if ('root' === (string) ($page['type'] ?? '')) {
                $roots[] = (int) $page['id'];
            }
        }

        sort($roots);

        return $roots;
    }

    public function languageConfigurations(int $rootPageId): array
    {
        ++$this->reads;
        $records = [];

        foreach ($this->tables['tl_inline_language'] ?? [] as $row) {
            if ((int) ($row['pid'] ?? 0) === $rootPageId) {
                $records[] = $row;
            }
        }

        return $records;
    }

    public function translations(string $translationTable, IntegrityScope $scope): array
    {
        ++$this->reads;
        $records = [];

        foreach ($this->tables[$translationTable] ?? [] as $row) {
            if (!$scope->coversLanguage((string) ($row['language'] ?? ''))) {
                continue;
            }

            if (!$scope->isInstallationWide() && $scope->rootPageId > 0) {
                $sourceTable = substr($translationTable, 0, -strlen('_translation'));
                $root = $this->rootPageIdOfSource($sourceTable, (int) ($row['pid'] ?? 0));

                if ($root > 0 && $root !== $scope->rootPageId) {
                    continue;
                }
            }

            $records[] = $row;
        }

        return $records;
    }

    public function sourceRecords(string $sourceTable, array $ids): array
    {
        ++$this->reads;
        $result = [];

        foreach ($ids as $id) {
            $row = $this->tables[$sourceTable][(int) $id] ?? null;

            if (null !== $row) {
                $result[(int) $id] = $row;
            }
        }

        return $result;
    }

    public function rootPageIdOfSource(string $sourceTable, int $sourceId): int
    {
        if ($sourceId <= 0) {
            return 0;
        }

        $pageId = match ($sourceTable) {
            'tl_page' => $sourceId,
            'tl_article' => (int) ($this->tables['tl_article'][$sourceId]['pid'] ?? 0),
            default => 0,
        };

        return $this->pageRoots[$pageId] ?? 0;
    }

    public function freeRecords(string $table, IntegrityScope $scope): array
    {
        ++$this->reads;
        $records = [];

        foreach ($this->tables[$table] ?? [] as $row) {
            $ownership = ContentOwnership::fromRecord($row);

            if ($ownership->isSource()) {
                continue;
            }

            if (!$scope->isInstallationWide() && $scope->rootPageId > 0 && $ownership->rootPageId !== $scope->rootPageId) {
                continue;
            }

            $records[] = $row;
        }

        return $records;
    }

    public function record(string $table, int $id): ?array
    {
        ++$this->reads;

        return $this->tables[$table][$id] ?? null;
    }

    public function tableExists(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * A deep copy used to prove that scanning did not change anything.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function snapshot(): array
    {
        return json_decode((string) json_encode($this->tables), true) ?? [];
    }
}
