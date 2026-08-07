<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


$GLOBALS['TL_DCA']['tl_calendar_events']['config']['onload_callback'][] = ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'handleTranslationRedirection'];
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['language_tabs'] = [
    'input_field_callback' => ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'renderTabs']
];

// Source-change tracking for connected translations (review status only).
\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationReviewDca::configureSource('tl_calendar_events');
