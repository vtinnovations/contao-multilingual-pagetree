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

\Contao\System::loadLanguageFile('tl_article');
\Contao\Controller::loadDataContainer('tl_article');

if (isset($GLOBALS['TL_LANG']['tl_article']) && is_array($GLOBALS['TL_LANG']['tl_article'])) {
    $GLOBALS['TL_LANG']['tl_article_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_article'],
        $GLOBALS['TL_LANG']['tl_article_translation'] ?? []
    );
}


// Forcefully require the model in case composer autoloader is missing it
$modelFile = __DIR__ . '/../../src/Model/ArticleTranslationModel.php';
if (file_exists($modelFile) && !class_exists(\Vtinnovations\ContaoMultilingualPagetree\Model\ArticleTranslationModel::class)) {
    require_once $modelFile;
}
$GLOBALS['TL_MODELS']['tl_article_translation'] = \Vtinnovations\ContaoMultilingualPagetree\Model\ArticleTranslationModel::class;

$GLOBALS['TL_DCA']['tl_article_translation'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_article',
        'enableVersioning' => true,
        'onload_callback'  => [
            ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'handleTranslationRedirection']
        ],
        'sql'              => [
            'keys' => [
                'id'           => 'primary',
                'pid,language' => 'unique',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'                  => 4,
            'fields'                => ['language'],
            'headerFields'          => ['title'],
            'panelLayout'           => 'filter;search,limit',
            'child_record_callback' => ['tl_article_translation_dca', 'listTranslations'],
        ],
        'label' => [
            'fields' => ['language'],
            'format' => '%s',
        ],
    ],
    'palettes' => [],
    'subpalettes' => [],
    'fields' => [
        'id' => [
            'sql' => "int(10) unsigned NOT NULL auto_increment",
        ],
        'pid' => [
            'foreignKey' => 'tl_article.id',
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'language' => [
            'label'            => &$GLOBALS['TL_LANG']['tl_article_translation']['language'],
            'inputType'        => 'text',
            'eval'             => ['doNotShow' => true],
            'sql'              => "varchar(7) NOT NULL default ''",
        ],
        'language_tabs' => [
            'input_field_callback' => ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'renderTabs'],
        ]
    ],
];

class tl_article_translation_dca extends \Contao\Backend
{
    public function listTranslations(array $row): string
    {
        return '<div class="tl_content_left">' . strtoupper($row['language']) . ' - ' . ($row['title'] ?? '') . '</div>';
    }
}

\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationPolicyDca::configure('tl_article_translation');
\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationStateDca::configure('tl_article_translation');
\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationReviewDca::configure('tl_article_translation');
\Vtinnovations\ContaoMultilingualPagetree\Backend\ContentModeDca::configureTranslation('tl_article_translation');
