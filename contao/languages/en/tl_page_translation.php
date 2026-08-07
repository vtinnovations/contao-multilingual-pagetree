<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


\Contao\System::loadLanguageFile('tl_page', 'en');
if (isset($GLOBALS['TL_LANG']['tl_page']) && is_array($GLOBALS['TL_LANG']['tl_page'])) {
    $GLOBALS['TL_LANG']['tl_page_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_page'],
        $GLOBALS['TL_LANG']['tl_page_translation'] ?? []
    );
}

$GLOBALS['TL_LANG']['tl_page_translation']['language'] = ['Language', 'Please select the language for this translation.'];
$GLOBALS['TL_LANG']['tl_page_translation']['title'] = ['Page name', 'Please enter the translated page name.'];
$GLOBALS['TL_LANG']['tl_page_translation']['alias'] = ['Page alias', 'Please enter the translated page alias (URL slug).'];
$GLOBALS['TL_LANG']['tl_page_translation']['pageTitle'] = ['Page title', 'Please enter the translated page title.'];
$GLOBALS['TL_LANG']['tl_page_translation']['description'] = ['Page description', 'Here you can enter a short description of the page which will be evaluated by search engines like Google or Yahoo.'];

$GLOBALS['TL_LANG']['tl_page_translation']['language_legend'] = 'Language';
$GLOBALS['TL_LANG']['tl_page_translation']['content_legend'] = 'Translated content';
$GLOBALS['TL_LANG']['tl_page_translation']['title_legend'] = 'Name and type';
$GLOBALS['TL_LANG']['tl_page_translation']['meta_legend'] = 'Meta information';
$GLOBALS['TL_LANG']['tl_page_translation']['publish_legend'] = 'Publish settings';
$GLOBALS['TL_LANG']['tl_page_translation']['expert_legend'] = 'Expert settings';

$GLOBALS['TL_LANG']['tl_page_translation']['new'] = ['New translation', 'Create a new translation'];
$GLOBALS['TL_LANG']['tl_page_translation']['edit'] = ['Edit translation', 'Edit translation ID %s'];
$GLOBALS['TL_LANG']['tl_page_translation']['delete'] = ['Delete translation', 'Delete translation ID %s'];
