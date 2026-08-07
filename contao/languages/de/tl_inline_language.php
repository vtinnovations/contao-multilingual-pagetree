<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


$GLOBALS['TL_LANG']['tl_inline_language']['language'] = ['Sprache', 'Wählen Sie die Sprache. Der zugehörige Sprachcode wird automatisch gespeichert.'];
$GLOBALS['TL_LANG']['tl_inline_language']['label'] = ['Sprachbezeichnung', 'Bitte geben Sie die Sprachbezeichnung ein (z. B. English, Deutsch).'];
$GLOBALS['TL_LANG']['tl_inline_language']['flag'] = ['Flagge', 'Wählen Sie die Flagge für diese Sprache. Eine Standardflagge wird automatisch ausgewählt und kann geändert werden.'];
$GLOBALS['TL_LANG']['tl_inline_language']['fallback'] = ['Historische Ausgangsmarkierung', 'Nur zur Kompatibilität. Maßgeblich ist die native Contao-Sprache des Startpunkts.'];
$GLOBALS['TL_LANG']['tl_inline_language']['published'] = ['Veröffentlichen', 'Diese Sprache im Frontend verfügbar machen.'];
$GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityMode'] = [
    'Seitenverfügbarkeit',
    'Legt fest, wie Seiten ohne Übersetzung in dieser Sprache behandelt werden.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityModes'] = [
    'strict' => 'Seiten ohne Übersetzung ausblenden',
    'fallback' => 'Standardseite anzeigen',
];
$GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityModeHelp'] = [
    'strict' => 'Seiten ohne verfügbare Übersetzung sind in dieser Sprache nicht erreichbar.',
    'fallback' => 'Seiten ohne verfügbare Übersetzung verwenden den aktuellen Seiteninhalt der Standardsprache und behalten dabei die angeforderte Sprach-URL und Oberflächensprache.',
];

$GLOBALS['TL_LANG']['tl_inline_language']['language_legend'] = 'Spracheinstellungen';
$GLOBALS['TL_LANG']['tl_inline_language']['availability_legend'] = 'Seitenverfügbarkeit';
$GLOBALS['TL_LANG']['tl_inline_language']['publish_legend'] = 'Veröffentlichung';

$GLOBALS['TL_LANG']['tl_inline_language']['new'] = ['Sprache hinzufügen', 'Eine zusätzliche Zielsprache zu diesem Startpunkt hinzufügen'];
$GLOBALS['TL_LANG']['tl_inline_language']['edit'] = ['Sprache bearbeiten', 'Sprache ID %s bearbeiten'];
$GLOBALS['TL_LANG']['tl_inline_language']['delete'] = ['Sprache löschen', 'Sprache ID %s löschen'];
$GLOBALS['TL_LANG']['tl_inline_language']['toggle'] = ['Sichtbarkeit umschalten', 'Sichtbarkeit der Sprache ID %s umschalten'];
$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationMode'] = [
    'Inhaltsstrukturmodus',
    'Verbundene Übersetzung: Die übersetzte Sprache folgt der Artikel- und Inhaltselementstruktur der Quelle. Redakteure übersetzen Felder, während Typ, Position, Reihenfolge und Beziehungen mit der Quelle verbunden bleiben. Freier Sprachinhalt: Die übersetzte Sprache hat eine eigenständige Artikel- und Inhaltsstruktur und kann vollständig von der Ausgangssprache abweichen.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationModes'] = [
    'connected' => 'Verbundene Übersetzung',
    'free' => 'Freier Sprachinhalt',
];
$GLOBALS['TL_LANG']['tl_inline_language']['contentFallbackMode'] = [
    'Inhaltsübersetzungsmodus',
    'Legt fest, wie nicht übersetzte Inhalte in dieser Sprache dargestellt werden.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['contentFallbackModes'] = [
    'strict' => 'Inhalte ohne Übersetzung nicht anzeigen',
    'fallback' => 'Standardinhalt anzeigen, wenn keine Übersetzung vorhanden ist',
];
$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationModeConfirm'] = [
    'Moduswechsel bestätigen',
    'Bestätigen Sie, dass gespeicherte Inhalte des anderen Modus nicht mehr ausgegeben werden. Es werden keine Daten gelöscht.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['defaultSourceLanguage'] = 'Standard-/Ausgangssprache';
$GLOBALS['TL_LANG']['tl_inline_language']['targetLanguage'] = 'Zielsprache';
$GLOBALS['TL_LANG']['tl_inline_language']['active'] = 'Aktiv';

$GLOBALS['TL_LANG']['tl_inline_language']['url_legend'] = 'Sprach-URL';
$GLOBALS['TL_LANG']['tl_inline_language']['urlProtocol'] = [
    'Protokoll',
    'Wählen Sie ein festes Protokoll oder übernehmen Sie das Protokoll der Website-Wurzel.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['urlProtocols'] = [
    '' => 'Von der Website-Wurzel übernehmen',
    'https' => 'HTTPS',
    'http' => 'HTTP',
];
$GLOBALS['TL_LANG']['tl_inline_language']['urlDomain'] = [
    'Domain',
    'Optional. Leer lassen, um die Domain der Website-Wurzel zu verwenden. Geben Sie nur einen Hostnamen ein, z. B. www.example.de.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['urlEntryPoint'] = [
    'Einstiegspfad',
    'Optionaler Sprachpfad, z. B. /de. Verwenden Sie / für das Domain-Stammverzeichnis.',
];
