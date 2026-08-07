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

namespace Vtinnovations\ContaoMultilingualPagetree\Backend;

use Contao\System;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationFieldPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Declares the columns of the content translation store.
 *
 * `tl_content_translation` is storage, never a backend table: the backend edits
 * the native content element and {@see ContentTranslationAdapter} moves the
 * translated values in and out. This class therefore contributes column
 * definitions and nothing else - no palettes, no widgets, no data container and
 * no operations.
 *
 * Each column reuses the `sql` of the matching native `tl_content` field, so a
 * translated value is stored in a column of exactly the same type as the value
 * it translates. The column set comes from the canonical policy alone, never
 * from a live database read, so the declared schema is identical in a web
 * request, in `contao:migrate` and during a cold cache warm-up.
 */
final class ContentTranslationSchema
{
    public static function configure(string $table = ContentTranslationFieldPolicy::TRANSLATION_TABLE): void
    {
        if (!isset($GLOBALS['TL_DCA'][$table])) {
            return;
        }

        try {
            \Contao\Controller::loadDataContainer(ContentTranslationFieldPolicy::SOURCE_TABLE);
        } catch (\Throwable) {
            // Without the source definition the columns below simply keep the
            // definitions the storage DCA already declares.
        }

        $sourceFields = $GLOBALS['TL_DCA'][ContentTranslationFieldPolicy::SOURCE_TABLE]['fields'] ?? [];
        $own = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        foreach (self::columns() as $column) {
            // The store owns its identity columns; a same-named source column
            // must never replace them.
            if (isset($own[$column])) {
                continue;
            }

            $sql = $sourceFields[$column]['sql'] ?? null;

            if (null === $sql) {
                continue;
            }

            // Storage only: the definition carries the column and nothing that
            // could render a widget or run a callback.
            $GLOBALS['TL_DCA'][$table]['fields'][$column] = [
                'sql' => $sql,
                'eval' => ['doNotShow' => true],
            ];
        }
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        $container = System::getContainer();

        try {
            if (null !== $container && $container->has(ContentTranslationFieldPolicy::class)) {
                return $container->get(ContentTranslationFieldPolicy::class)->persistedColumns();
            }
        } catch (\Throwable) {
            // Fall through to a freshly built policy below.
        }

        return (new ContentTranslationFieldPolicy(new TranslationFieldRegistry()))->persistedColumns();
    }
}
