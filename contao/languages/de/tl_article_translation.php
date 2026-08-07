<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


\Contao\System::loadLanguageFile('tl_article', 'de');
if (isset($GLOBALS['TL_LANG']['tl_article']) && is_array($GLOBALS['TL_LANG']['tl_article'])) {
    $GLOBALS['TL_LANG']['tl_article_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_article'],
        $GLOBALS['TL_LANG']['tl_article_translation'] ?? []
    );
}

$GLOBALS['TL_LANG']['tl_article_translation']['language'] = ['Sprache', 'Bitte wählen Sie die Sprache für diese Übersetzung aus.'];
$GLOBALS['TL_LANG']['tl_article_translation']['title'] = ['Titel', 'Bitte geben Sie den übersetzten Artikeltitel ein.'];
$GLOBALS['TL_LANG']['tl_article_translation']['teaser'] = ['Teasertext', 'Der Teasertext kann mit dem Artikel-Modul dargestellt werden.'];

$GLOBALS['TL_LANG']['tl_article_translation']['language_legend'] = 'Sprache';
$GLOBALS['TL_LANG']['tl_article_translation']['content_legend'] = 'Übersetzter Inhalt';
$GLOBALS['TL_LANG']['tl_article_translation']['title_legend'] = 'Titel und Autor';
$GLOBALS['TL_LANG']['tl_article_translation']['teaser_legend'] = 'Artikel-Teaser';
$GLOBALS['TL_LANG']['tl_article_translation']['image_legend'] = 'Teaser-Bild';
$GLOBALS['TL_LANG']['tl_article_translation']['publish_legend'] = 'Veröffentlichung';
$GLOBALS['TL_LANG']['tl_article_translation']['expert_legend'] = 'Experten-Einstellungen';

$GLOBALS['TL_LANG']['tl_article_translation']['new'] = ['Neue Übersetzung', 'Eine neue Übersetzung erstellen'];
$GLOBALS['TL_LANG']['tl_article_translation']['edit'] = ['Übersetzung bearbeiten', 'Übersetzung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_article_translation']['delete'] = ['Übersetzung löschen', 'Übersetzung ID %s löschen'];
