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
use Vtinnovations\ContaoMultilingualPagetree\Content\FreeContentStorageInterface;

/**
 * In-memory article/content storage for the free-mode service tests.
 */
class InMemoryFreeContentStorage implements FreeContentStorageInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = ['tl_article' => [], 'tl_content' => []];

    /** @var array<string, int> */
    public array $translationCounts = [];

    public int $nextId = 1000;
    public bool $inTransaction = false;
    public bool $rolledBack = false;
    public bool $committed = false;
    public bool $failInsert = false;

    /**
     * @param array<string, mixed> $row
     */
    public function put(string $table, array $row): self
    {
        $this->tables[$table][(int) ($row['id'] ?? 0)] = $row;

        return $this;
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
        return $this->translationCounts[$translationTable.'|'.$language] ?? 0;
    }

    public function findSourceArticles(int $pageId): array
    {
        $articles = [];

        foreach ($this->tables['tl_article'] ?? [] as $row) {
            if ((int) ($row['pid'] ?? 0) === $pageId && ContentOwnership::fromRecord($row)->isSource()) {
                $articles[] = $row;
            }
        }

        usort($articles, static fn (array $a, array $b): int => ((int) ($a['sorting'] ?? 0)) <=> ((int) ($b['sorting'] ?? 0)));

        return $articles;
    }

    public function findChildContent(string $parentTable, int $parentId): array
    {
        $children = [];

        foreach ($this->tables['tl_content'] ?? [] as $row) {
            if ((int) ($row['pid'] ?? 0) === $parentId && (string) ($row['ptable'] ?? 'tl_article') === $parentTable) {
                $children[] = $row;
            }
        }

        usort($children, static fn (array $a, array $b): int => ((int) ($a['sorting'] ?? 0)) <=> ((int) ($b['sorting'] ?? 0)));

        return $children;
    }

    public function findRecord(string $table, int $id): ?array
    {
        return $this->tables[$table][$id] ?? null;
    }

    public function insertRecord(string $table, array $row): int
    {
        if ($this->failInsert) {
            throw new \RuntimeException('Insert failed');
        }

        $id = $this->nextId++;
        $row['id'] = $id;
        $this->tables[$table][$id] = $row;

        return $id;
    }

    public function columns(string $table): array
    {
        $columns = ['id', 'pid', 'ptable', 'sorting', 'tstamp', 'type', 'headline', 'text', 'title', 'alias',
            'inColumn', 'published', 'start', 'stop', 'customTpl', 'protected', 'groups',
            ContentOwnership::FIELD_LANGUAGE, ContentOwnership::FIELD_ROOT];

        return $columns;
    }

    public function beginTransaction(): void
    {
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->inTransaction = false;
        $this->committed = true;
    }

    public function rollBack(): void
    {
        $this->inTransaction = false;
        $this->rolledBack = true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function freeRecords(string $table, string $language): array
    {
        $records = [];

        foreach ($this->tables[$table] ?? [] as $row) {
            if (ContentOwnership::fromRecord($row)->belongsTo($language)) {
                $records[] = $row;
            }
        }

        return $records;
    }

    private function countFree(string $table, int $rootPageId, string $language): int
    {
        $count = 0;

        foreach ($this->tables[$table] ?? [] as $row) {
            $ownership = ContentOwnership::fromRecord($row);

            if ($ownership->belongsTo($language) && (0 === $rootPageId || $ownership->rootPageId === $rootPageId)) {
                ++$count;
            }
        }

        return $count;
    }
}
