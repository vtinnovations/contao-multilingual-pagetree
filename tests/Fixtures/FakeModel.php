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

/**
 * Minimal stand-in for a Contao model.
 *
 * It exposes the same row/setRow/magic property contract the bundle relies on,
 * which keeps the overlay tests independent from a bootstrapped Contao runtime.
 */
class FakeModel
{
    /** @var array<string, mixed> */
    private array $data;

    private string $table;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $table, array $data)
    {
        $this->table = $table;
        $this->data = $data;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function setRow(array $row): static
    {
        $this->data = $row;

        return $this;
    }

    public function __get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }
}
