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

\Contao\System::loadLanguageFile('tl_page');
\Contao\Controller::loadDataContainer('tl_page');

if (isset($GLOBALS['TL_LANG']['tl_page']) && is_array($GLOBALS['TL_LANG']['tl_page'])) {
    $GLOBALS['TL_LANG']['tl_page_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_page'],
        $GLOBALS['TL_LANG']['tl_page_translation'] ?? []
    );
}


// Forcefully require the model in case composer autoloader is missing it
$modelFile = __DIR__ . '/../../src/Model/PageTranslationModel.php';
if (file_exists($modelFile) && !class_exists(\Vtinnovations\ContaoMultilingualPagetree\Model\PageTranslationModel::class)) {
    require_once $modelFile;
}
$GLOBALS['TL_MODELS']['tl_page_translation'] = \Vtinnovations\ContaoMultilingualPagetree\Model\PageTranslationModel::class;

$GLOBALS['TL_DCA']['tl_page_translation'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_page',
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
            'headerFields'          => ['title', 'type'],
            'panelLayout'           => 'filter;search,limit',
            'child_record_callback' => ['tl_page_translation_dca', 'listTranslations'],
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
            'foreignKey' => 'tl_page.id',
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'language' => [
            'label'            => &$GLOBALS['TL_LANG']['tl_page_translation']['language'],
            'inputType'        => 'text',
            'eval'             => ['doNotShow' => true],
            'sql'              => "varchar(7) NOT NULL default ''",
        ],
        'language_tabs' => [
            'input_field_callback' => ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'renderTabs'],
        ]
    ],
];

class tl_page_translation_dca extends \Contao\Backend
{
    public function generateAlias($varValue, \Contao\DataContainer $dc): string
    {
        $aliasExists = function (string $alias) use ($dc): bool {
            return $this->Database->prepare("SELECT id FROM tl_page_translation WHERE alias=? AND id!=? AND language=?")
                ->execute($alias, (int) $dc->id, $dc->activeRecord->language ?: \Contao\Input::get('language'))
                ->numRows > 0;
        };

        // Generate alias if there is none
        if (!strlen((string) $varValue)) {
            $varValue = \Contao\StringUtil::generateAlias($dc->activeRecord->title ?: '');
        }

        $varValue = \Contao\StringUtil::generateAlias((string) $varValue);

        if ($aliasExists($varValue)) {
            $varValue .= '-' . $dc->id;
        }

        return $varValue;
    }

    public function listTranslations(array $row): string
    {
        $aliasInfo = !empty($row['alias']) ? ' <span style="color:#999;padding-left:3px">[' . $row['alias'] . ']</span>' : '';
        return '<div class="tl_content_left">' . strtoupper($row['language']) . ' - ' . ($row['title'] ?? '') . $aliasInfo . '</div>';
    }
}

\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationPolicyDca::configure(
    'tl_page_translation',
    ['tl_page_translation_dca', 'generateAlias'],
);
\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationStateDca::configure('tl_page_translation');
\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationReviewDca::configure('tl_page_translation');
