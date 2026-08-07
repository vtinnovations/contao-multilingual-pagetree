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

/**
 * Persistence seam of the free-content layer.
 *
 * Every lookup is scoped by language and root site so records can never cross a
 * language or site boundary.
 */
interface FreeContentStorageInterface
{
    public function countFreeArticles(int $rootPageId, string $language): int;

    public function countFreeContentElements(int $rootPageId, string $language): int;

    public function countConnectedTranslations(string $translationTable, string $language): int;

    /**
     * Source (default-language) articles of one page, in rendering order.
     *
     * @return list<array<string, mixed>>
     */
    public function findSourceArticles(int $pageId): array;

    /**
     * Child content records of one owner, in sorting order.
     *
     * @return list<array<string, mixed>>
     */
    public function findChildContent(string $parentTable, int $parentId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findRecord(string $table, int $id): ?array;

    /**
     * @param array<string, mixed> $row
     *
     * @return int The id of the created record
     */
    public function insertRecord(string $table, array $row): int;

    /**
     * @return list<string>
     */
    public function columns(string $table): array;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
