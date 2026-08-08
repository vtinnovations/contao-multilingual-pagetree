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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Integrity;

use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityWriterInterface;

final class RecordingWriter implements IntegrityWriterInterface
{
    /** @var list<array{table: string, id: int, changes: array<string, mixed>}> */
    public array $updates = [];

    /** @var list<int> */
    public array $quarantined = [];

    /** @var list<int> */
    public array $deleted = [];

    public bool $failDeletes = false;
    public bool $rolledBack = false;
    public bool $committed = false;

    public function updateRecord(string $table, int $id, array $changes): bool
    {
        $this->updates[] = ['table' => $table, 'id' => $id, 'changes' => $changes];

        return true;
    }

    public function quarantineRecord(string $table, int $id): bool
    {
        $this->quarantined[] = $id;

        return true;
    }

    public function deleteRecord(string $table, int $id): bool
    {
        if ($this->failDeletes) {
            return false;
        }

        $this->deleted[] = $id;

        return true;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): void
    {
        $this->committed = true;
    }

    public function rollBack(): void
    {
        $this->rolledBack = true;
    }

    public function supportsTransactions(): bool
    {
        return true;
    }
}
