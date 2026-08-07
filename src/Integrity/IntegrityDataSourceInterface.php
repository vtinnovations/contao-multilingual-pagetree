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

/**
 * Read-only access to the records an integrity rule inspects.
 *
 * Every method is scoped by root site and, where applicable, by language: no
 * rule can ever read across a site boundary, and no method writes.
 */
interface IntegrityDataSourceInterface
{
    /**
     * Root page ids in the installation, or just the scoped one.
     *
     * @return list<int>
     */
    public function rootPageIds(IntegrityScope $scope): array;

    /**
     * Language configuration records of one root site.
     *
     * @return list<array<string, mixed>>
     */
    public function languageConfigurations(int $rootPageId): array;

    /**
     * Connected translation records of one translation table within a root site.
     *
     * @return list<array<string, mixed>>
     */
    public function translations(string $translationTable, IntegrityScope $scope): array;

    /**
     * Source records addressed by the given ids, keyed by id.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function sourceRecords(string $sourceTable, array $ids): array;

    /**
     * The root page id a source record belongs to, or 0 when it cannot be
     * determined.
     */
    public function rootPageIdOfSource(string $sourceTable, int $sourceId): int;

    /**
     * Free-language article and content records of one root site.
     *
     * @return list<array<string, mixed>>
     */
    public function freeRecords(string $table, IntegrityScope $scope): array;

    /**
     * @return array<string, mixed>|null
     */
    public function record(string $table, int $id): ?array;

    public function tableExists(string $table): bool;
}
