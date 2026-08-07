<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


use Contao\DC_Table;
use Vtinnovations\ContaoMultilingualPagetree\Schema\BundleSchema;

// Internal storage only: this DCA owns the table schema but intentionally has
// no palettes, operations or backend module registration.
$GLOBALS['TL_DCA']['tl_multilingual_pagetree_channel_ledger'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => [
            'engine' => 'InnoDB',
            'charset' => 'utf8mb4',
            'keys' => [
                'request_id' => 'primary',
            ],
        ],
    ],
    'fields' => array_map(
        static fn (array $definition): array => ['sql' => $definition['sql']],
        BundleSchema::LEDGER_COLUMNS,
    ),
];
