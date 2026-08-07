<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


\Contao\System::loadLanguageFile('tl_content', 'de');
if (isset($GLOBALS['TL_LANG']['tl_content']) && is_array($GLOBALS['TL_LANG']['tl_content'])) {
    $GLOBALS['TL_LANG']['tl_content_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_content'],
        $GLOBALS['TL_LANG']['tl_content_translation'] ?? []
    );
}

$GLOBALS['TL_LANG']['tl_content_translation']['language']       = ['Sprache', 'Bitte wählen Sie die Sprache für diese Übersetzung aus.'];
$GLOBALS['TL_LANG']['tl_content_translation']['headline_value'] = ['Überschrift', 'Geben Sie den übersetzten Text der Überschrift ein. Leer lassen, um das Original zu behalten.'];
$GLOBALS['TL_LANG']['tl_content_translation']['headline_unit']  = ['Ebene der Überschrift', 'Optionale Änderung der Überschriftenebene (h1–h6). Leer lassen, um das Original zu behalten.'];
$GLOBALS['TL_LANG']['tl_content_translation']['text']           = ['Text', 'Sie können den HTML-Editor verwenden, um den Text zu formatieren.'];
$GLOBALS['TL_LANG']['tl_content_translation']['html']           = ['HTML-Code', 'Sie können die Liste der erlaubten HTML-Tags in den Backend-Einstellungen bearbeiten.'];
$GLOBALS['TL_LANG']['tl_content_translation']['code']           = ['Quellcode', 'Bitte beachten Sie, dass der Quellcode nicht ausgeführt wird.'];
$GLOBALS['TL_LANG']['tl_content_translation']['linkTitle']      = ['Link-Titel', 'Der Link-Titel wird anstelle der Ziel-URL angezeigt.'];
$GLOBALS['TL_LANG']['tl_content_translation']['titleText']      = ['Link-Text', 'Der Link-Text wird angezeigt, falls das Bild nicht geladen werden kann.'];
$GLOBALS['TL_LANG']['tl_content_translation']['caption']        = ['Bildunterschrift', 'Hier können Sie einen kurzen Text eingeben, der unter dem Bild angezeigt wird.'];
$GLOBALS['TL_LANG']['tl_content_translation']['alt']            = ['Alternativtext', 'Ein barrierefreier Alternativtext für das Bild.'];
$GLOBALS['TL_LANG']['tl_content_translation']['imageTitle']     = ['Bildtitel', 'Hier können Sie einen Titel für das Bild eingeben.'];
$GLOBALS['TL_LANG']['tl_content_translation']['url']            = ['Ziel-URL', 'Bitte geben Sie die Web-Adresse (http://...) oder einen Dateipfad ein.'];
$GLOBALS['TL_LANG']['tl_content_translation']['playerCaption']  = ['Player-Beschriftung', 'Hier können Sie eine Beschriftung für den Media-Player eingeben.'];
$GLOBALS['TL_LANG']['tl_content_translation']['listitems']      = ['Listeneinträge', 'Hier können Sie die Listeneinträge verwalten.'];
$GLOBALS['TL_LANG']['tl_content_translation']['tableitems']     = ['Tabelleneinträge', 'Hier können Sie die Tabelleneinträge verwalten.'];
$GLOBALS['TL_LANG']['tl_content_translation']['summary']        = ['Zusammenfassung der Tabelle', 'Bitte geben Sie eine kurze Zusammenfassung der Tabelle und ihrer Struktur ein.'];
$GLOBALS['TL_LANG']['tl_content_translation']['data']           = ['Definitionsliste Begriffe & Werte', 'Übersetzen Sie die Schlüssel/Wert-Paare der Definitionsliste.'];

$GLOBALS['TL_LANG']['tl_content_translation']['language_legend'] = 'Sprache';
$GLOBALS['TL_LANG']['tl_content_translation']['content_legend']  = 'Übersetzter Inhalt';
$GLOBALS['TL_LANG']['tl_content_translation']['title_legend']    = 'Titel und Typ';
$GLOBALS['TL_LANG']['tl_content_translation']['type_legend']     = 'Elementtyp';
$GLOBALS['TL_LANG']['tl_content_translation']['text_legend']     = 'Text / HTML / Code';
$GLOBALS['TL_LANG']['tl_content_translation']['headline_legend'] = 'Überschrift';
$GLOBALS['TL_LANG']['tl_content_translation']['image_legend']    = 'Bild-Einstellungen';
$GLOBALS['TL_LANG']['tl_content_translation']['media_legend']    = 'Media-Einstellungen';
$GLOBALS['TL_LANG']['tl_content_translation']['link_legend']     = 'Link-Einstellungen';
$GLOBALS['TL_LANG']['tl_content_translation']['list_legend']     = 'Listeneinträge';
$GLOBALS['TL_LANG']['tl_content_translation']['table_legend']    = 'Tabelleneinträge';
$GLOBALS['TL_LANG']['tl_content_translation']['template_legend'] = 'Template-Einstellungen';
$GLOBALS['TL_LANG']['tl_content_translation']['publish_legend']  = 'Veröffentlichung';
$GLOBALS['TL_LANG']['tl_content_translation']['expert_legend']   = 'Experten-Einstellungen';

$GLOBALS['TL_LANG']['tl_content_translation']['new']    = ['Neue Übersetzung', 'Eine neue Übersetzung erstellen'];
$GLOBALS['TL_LANG']['tl_content_translation']['edit']   = ['Übersetzung bearbeiten', 'Übersetzung ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_content_translation']['delete'] = ['Übersetzung löschen', 'Übersetzung ID %s löschen'];
