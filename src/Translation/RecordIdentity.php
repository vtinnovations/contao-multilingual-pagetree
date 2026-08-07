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

/**
 * Identifies the record Contao passes into a rendering hook.
 *
 * Contao may hand over a model, a database result or a plain object, and third
 * party bundles may use their own model subclasses. The table is therefore read
 * from the record itself instead of matching against a list of known classes.
 */
final class RecordIdentity
{
    public static function table(object $record): ?string
    {
        if (!method_exists($record, 'getTable')) {
            return null;
        }

        try {
            $table = $record->getTable();
        } catch (\Throwable) {
            return null;
        }

        return is_string($table) && '' !== $table ? $table : null;
    }

    public static function id(object $record): int
    {
        try {
            $id = $record->id ?? null;
        } catch (\Throwable) {
            return 0;
        }

        return is_numeric($id) ? (int) $id : 0;
    }
}
