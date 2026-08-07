<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


$GLOBALS['TL_DCA']['tl_article']['config']['onload_callback'][] = ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'handleTranslationRedirection'];
$GLOBALS['TL_DCA']['tl_article']['fields']['language_tabs'] = [
    'input_field_callback' => ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'renderTabs']
];

// Source-change tracking for connected translations (review status only).
\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationReviewDca::configureSource('tl_article');

// Point 9: language ownership of free-mode records (connected mode leaves this empty).
\Vtinnovations\ContaoMultilingualPagetree\Backend\ContentModeDca::configureSource('tl_article');
