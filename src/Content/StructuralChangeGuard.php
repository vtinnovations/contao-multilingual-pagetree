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
 * Enforces structural inheritance of connected translations, server side.
 *
 * A connected translation record never owns structure: parent relation, type,
 * column, sorting and template configuration all belong to the source record.
 * The point 7 policy already keeps those fields out of the translation form;
 * this guard rejects them again for any other write path (copy, move, import,
 * programmatic edits).
 */
final class StructuralChangeGuard
{
    /**
     * Fields a connected translation record may never define or change.
     */
    private const PROTECTED_FIELDS = [
        'pid', 'ptable', 'sorting', 'type', 'CType', 'colPos', 'inColumn',
        'parent', 'customTpl', 'protected', 'groups',
    ];

    /**
     * Backend actions a connected translation record never supports.
     */
    private const PROTECTED_ACTIONS = ['cut', 'cutAll', 'copy', 'copyAll', 'paste', 'move'];

    public function isConnectedTranslationTable(string $table): bool
    {
        return str_ends_with($table, '_translation');
    }

    public function isProtectedField(string $field): bool
    {
        return in_array($field, self::PROTECTED_FIELDS, true);
    }

    public function isProtectedAction(?string $action): bool
    {
        return is_string($action) && in_array($action, self::PROTECTED_ACTIONS, true);
    }

    /**
     * Fields of a submitted change set that a connected translation may not
     * change. An empty result means the change set is acceptable.
     *
     * @param array<string, mixed> $changeSet
     * @param array<string, mixed> $currentRow
     *
     * @return list<string>
     */
    public function rejectedFields(string $table, array $changeSet, array $currentRow = []): array
    {
        if (!$this->isConnectedTranslationTable($table)) {
            return [];
        }

        $rejected = [];

        foreach ($changeSet as $field => $value) {
            if (!is_string($field) || !$this->isProtectedField($field)) {
                continue;
            }

            // Writing the unchanged inherited value is not a structural change.
            if (array_key_exists($field, $currentRow) && $this->sameValue($currentRow[$field], $value)) {
                continue;
            }

            $rejected[] = $field;
        }

        return $rejected;
    }

    /**
     * @param array<string, mixed> $changeSet
     * @param array<string, mixed> $currentRow
     */
    public function isStructuralChange(string $table, array $changeSet, array $currentRow = []): bool
    {
        return [] !== $this->rejectedFields($table, $changeSet, $currentRow);
    }

    private function sameValue(mixed $current, mixed $new): bool
    {
        if (is_scalar($current) && is_scalar($new)) {
            return (string) $current === (string) $new;
        }

        return $current === $new;
    }
}
