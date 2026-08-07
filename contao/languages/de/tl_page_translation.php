<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


\Contao\System::loadLanguageFile('tl_page', 'de');
if (isset($GLOBALS['TL_LANG']['tl_page']) && is_array($GLOBALS['TL_LANG']['tl_page'])) {
    $GLOBALS['TL_LANG']['tl_page_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_page'],
        $GLOBALS['TL_LANG']['tl_page_translation'] ?? []
    );
}

$GLOBALS['TL_LANG']['tl_page_translation']['language'] = ['Sprache', 'Bitte wählen Sie die Sprache für diese Übersetzung aus.'];
$GLOBALS['TL_LANG']['tl_page_translation']['title'] = ['Seitenname', 'Bitte geben Sie den übersetzten Seitennamen ein.'];
$GLOBALS['TL_LANG']['tl_page_translation']['alias'] = ['Seitenalias', 'Bitte geben Sie den übersetzten Seitenalias (URL-Slug) ein.'];
$GLOBALS['TL_LANG']['tl_page_translation']['pageTitle'] = ['Seitentitel', 'Bitte geben Sie den übersetzten Seitentitel ein.'];
$GLOBALS['TL_LANG']['tl_page_translation']['description'] = ['Beschreibung der Seite', 'Hier können Sie eine kurze Beschreibung der Seite eingeben, die von Suchmaschinen wie Google oder Yahoo ausgewertet wird.'];

$GLOBALS['TL_LANG']['tl_page_translation']['language_legend'] = 'Sprache';
$GLOBALS['TL_LANG']['tl_page_translation']['content_legend'] = 'Übersetzter Inhalt';
$GLOBALS['TL_LANG']['tl_page_translation']['title_legend'] = 'Name und Typ';
$GLOBALS['TL_LANG']['tl_page_translation']['meta_legend'] = 'Meta-Informationen';
$GLOBALS['TL_LANG']['tl_page_translation']['publish_legend'] = 'Veröffentlichung';
$GLOBALS['TL_LANG']['tl_page_translation']['expert_legend'] = 'Experten-Einstellungen';

$GLOBALS['TL_LANG']['tl_page_translation']['new'] = ['Neue Übersetzung', 'Eine neue Übersetzung erstellen'];
$GLOBALS['TL_LANG']['tl_page_translation']['edit'] = ['Übersetzung bearbeiten', 'Übersetzung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_page_translation']['delete'] = ['Übersetzung löschen', 'Übersetzung ID %s löschen'];
