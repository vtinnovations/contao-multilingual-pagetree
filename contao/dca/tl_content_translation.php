<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


\Contao\System::loadLanguageFile('tl_content');
\Contao\Controller::loadDataContainer('tl_content');

if (isset($GLOBALS['TL_LANG']['tl_content']) && is_array($GLOBALS['TL_LANG']['tl_content'])) {
    $GLOBALS['TL_LANG']['tl_content_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_content'],
        $GLOBALS['TL_LANG']['tl_content_translation'] ?? []
    );
}


// Forcefully require the model in case composer autoloader is missing it
$modelFile = __DIR__ . '/../../src/Model/ContentTranslationModel.php';
if (file_exists($modelFile) && !class_exists(\Vtinnovations\ContaoMultilingualPagetree\Model\ContentTranslationModel::class)) {
    require_once $modelFile;
}
$GLOBALS['TL_MODELS']['tl_content_translation'] = \Vtinnovations\ContaoMultilingualPagetree\Model\ContentTranslationModel::class;

// Storage only. `tl_content_translation` holds one row per source element and
// language; it is never opened in the backend. Additional-language content is
// edited through the native tl_content form, and ContentTranslationAdapter
// moves the translated values in and out. The definition therefore declares
// columns and nothing else: no data container, no palettes, no operations - a
// table hanging below tl_content through `ptable` would be a third level under
// the article module, which Contao has no edit operation for.
$GLOBALS['TL_DCA']['tl_content_translation'] = [
    'config' => [
        'notEditable' => true,
        'notCreatable' => true,
        'notDeletable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'closed' => true,
        'sql' => [
            'keys' => [
                'id'           => 'primary',
                'pid,language' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => "int(10) unsigned NOT NULL auto_increment",
        ],
        'pid' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'language' => [
            'sql' => "varchar(7) NOT NULL default ''",
        ],
        // Provenance of every translated value: inherit, custom or empty. It is
        // derived automatically when the form is submitted and is never
        // rendered as an editor-facing control.
        'fieldStates' => [
            'sql' => "text NULL",
        ],
    ],
];

// The translatable columns reuse the SQL of their native tl_content field.
\Vtinnovations\ContaoMultilingualPagetree\Backend\ContentTranslationSchema::configure('tl_content_translation');
