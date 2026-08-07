<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


\Contao\System::loadLanguageFile('tl_article', 'en');
if (isset($GLOBALS['TL_LANG']['tl_article']) && is_array($GLOBALS['TL_LANG']['tl_article'])) {
    $GLOBALS['TL_LANG']['tl_article_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_article'],
        $GLOBALS['TL_LANG']['tl_article_translation'] ?? []
    );
}

$GLOBALS['TL_LANG']['tl_article_translation']['language'] = ['Language', 'Please select the language for this translation.'];
$GLOBALS['TL_LANG']['tl_article_translation']['title'] = ['Title', 'Please enter the translated article title.'];
$GLOBALS['TL_LANG']['tl_article_translation']['teaser'] = ['Article teaser', 'The article teaser can also be displayed with the article module.'];

$GLOBALS['TL_LANG']['tl_article_translation']['language_legend'] = 'Language';
$GLOBALS['TL_LANG']['tl_article_translation']['content_legend'] = 'Translated content';
$GLOBALS['TL_LANG']['tl_article_translation']['title_legend'] = 'Title and author';
$GLOBALS['TL_LANG']['tl_article_translation']['teaser_legend'] = 'Article teaser';
$GLOBALS['TL_LANG']['tl_article_translation']['image_legend'] = 'Teaser image';
$GLOBALS['TL_LANG']['tl_article_translation']['publish_legend'] = 'Publish settings';
$GLOBALS['TL_LANG']['tl_article_translation']['expert_legend'] = 'Expert settings';

$GLOBALS['TL_LANG']['tl_article_translation']['new'] = ['New translation', 'Create a new translation'];
$GLOBALS['TL_LANG']['tl_article_translation']['edit'] = ['Edit translation', 'Edit translation ID %s'];
$GLOBALS['TL_LANG']['tl_article_translation']['delete'] = ['Delete translation', 'Delete translation ID %s'];
