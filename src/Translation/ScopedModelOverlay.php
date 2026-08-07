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

namespace Vtinnovations\ContaoMultilingualPagetree\Translation;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Applies a prepared translation row to the record Contao is about to render
 * and restores the original values once that render operation is finished.
 *
 * Contao hands the very same model instance to the legacy content element, to
 * the fragment reference and to the article module, so the overlay has to live
 * on that instance for the duration of a single render. Because those instances
 * come from Contao's model registry and are shared, every overlay is snapshotted
 * and released again; nothing is ever persisted and no other language rendered
 * later in the same process can observe a translated value.
 */
final class ScopedModelOverlay implements ResetInterface
{
    /**
     * A strong reference to the record is kept on purpose: it guarantees that
     * spl_object_id() cannot be recycled for a different object while an
     * overlay is active.
     *
     * @var array<int, array{record: object, row: array<string, mixed>}>
     */
    private array $active = [];

    /** @var array<string, int> */
    private array $tokens = [];

    public function isActive(object $record): bool
    {
        return isset($this->active[spl_object_id($record)]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return bool True if the record was overlaid and has to be restored later
     */
    public function apply(object $record, array $row, ?string $token = null): bool
    {
        $key = spl_object_id($record);

        // Never snapshot an already translated state as the "original" one.
        if (isset($this->active[$key])) {
            return false;
        }

        $original = $this->readRow($record);

        if ($original === $row) {
            return false;
        }

        try {
            $this->writeRow($record, $row);
        } catch (\Throwable) {
            $this->writeRowQuietly($record, $original);

            return false;
        }

        $this->active[$key] = ['record' => $record, 'row' => $original];

        if (null !== $token) {
            $this->tokens[$token] = $key;
        }

        return true;
    }

    public function restore(object $record): void
    {
        $this->restoreKey(spl_object_id($record));
    }

    public function restoreToken(string $token): void
    {
        if (!isset($this->tokens[$token])) {
            return;
        }

        $key = $this->tokens[$token];
        unset($this->tokens[$token]);
        $this->restoreKey($key);
    }

    /**
     * Safety net for render operations that were aborted by an exception or by
     * a code path that never reaches the matching release hook.
     */
    public function restoreAll(): void
    {
        foreach (array_keys($this->active) as $key) {
            $this->restoreKey($key);
        }

        $this->active = [];
        $this->tokens = [];
    }

    public function reset(): void
    {
        $this->restoreAll();
    }

    private function restoreKey(int $key): void
    {
        if (!isset($this->active[$key])) {
            return;
        }

        $entry = $this->active[$key];
        unset($this->active[$key]);

        foreach ($this->tokens as $token => $tokenKey) {
            if ($tokenKey === $key) {
                unset($this->tokens[$token]);
            }
        }

        $this->writeRowQuietly($entry['record'], $entry['row']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readRow(object $record): array
    {
        if (method_exists($record, 'row')) {
            $row = $record->row();

            if (is_array($row)) {
                return $row;
            }
        }

        return get_object_vars($record);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function writeRow(object $record, array $row): void
    {
        // Contao models expose the whole row; replacing it keeps runtime
        // properties intact and avoids marking fields as modified.
        if (method_exists($record, 'setRow')) {
            $record->setRow($row);

            return;
        }

        if ($record instanceof \stdClass) {
            foreach (array_keys(get_object_vars($record)) as $field) {
                if (!array_key_exists($field, $row)) {
                    unset($record->$field);
                }
            }
        }

        foreach ($row as $field => $value) {
            $record->$field = $value;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function writeRowQuietly(object $record, array $row): void
    {
        try {
            $this->writeRow($record, $row);
        } catch (\Throwable) {
            // A record that cannot be written back must never break the frontend.
        }
    }
}
