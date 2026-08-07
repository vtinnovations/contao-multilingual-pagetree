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
 * The only write path of the integrity subsystem.
 *
 * Every method is constrained by table and record id resolved from a verified
 * plan; nothing is ever deleted by language alone or by alias matching.
 */
interface IntegrityWriterInterface
{
    /**
     * @param array<string, scalar|null> $changes
     */
    public function updateRecord(string $table, int $id, array $changes): bool;

    /**
     * Makes a record inactive without losing its data.
     */
    public function quarantineRecord(string $table, int $id): bool;

    public function deleteRecord(string $table, int $id): bool;

    public function beginTransaction(): bool;

    public function commit(): void;

    public function rollBack(): void;

    public function supportsTransactions(): bool;
}
