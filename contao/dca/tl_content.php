<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


$GLOBALS['TL_DCA']['tl_content']['config']['onload_callback'][] = ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'handleTranslationRedirection'];
$GLOBALS['TL_DCA']['tl_content']['fields']['language_tabs'] = [
    'input_field_callback' => ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'renderTabs']
];

// Point 9: language ownership of free-mode records (connected mode leaves this empty).
\Vtinnovations\ContaoMultilingualPagetree\Backend\ContentModeDca::configureSource('tl_content');

// Additional-language editing happens on this very form: the adapter swaps the
// approved values for the selected language and keeps the source row unchanged.
\Vtinnovations\ContaoMultilingualPagetree\Backend\ContentTranslationAdapter::configure('tl_content');
