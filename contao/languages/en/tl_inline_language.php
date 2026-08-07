<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


$GLOBALS['TL_LANG']['tl_inline_language']['language'] = ['Language', 'Select the language. The corresponding language code is stored automatically.'];
$GLOBALS['TL_LANG']['tl_inline_language']['label'] = ['Language label', 'Please enter the language label (e.g. English, Deutsch).'];
$GLOBALS['TL_LANG']['tl_inline_language']['flag'] = ['Flag', 'Select the flag shown for this language. A default flag is chosen automatically and can be changed.'];
$GLOBALS['TL_LANG']['tl_inline_language']['fallback'] = ['Legacy source marker', 'Compatibility field only. The native Contao site-root language is authoritative.'];
$GLOBALS['TL_LANG']['tl_inline_language']['published'] = ['Publish', 'Make this language available in the frontend.'];
$GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityMode'] = [
    'Page availability',
    'Controls how pages without a translation behave in this language.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityModes'] = [
    'strict' => 'Hide pages without translation',
    'fallback' => 'Show default page',
];
$GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityModeHelp'] = [
    'strict' => 'Pages without an available translation are not accessible in this language.',
    'fallback' => 'Pages without an available translation use the current default-language page content while retaining the requested language URL and interface language.',
];

$GLOBALS['TL_LANG']['tl_inline_language']['language_legend'] = 'Language settings';
$GLOBALS['TL_LANG']['tl_inline_language']['availability_legend'] = 'Page availability';
$GLOBALS['TL_LANG']['tl_inline_language']['publish_legend'] = 'Publish settings';

$GLOBALS['TL_LANG']['tl_inline_language']['new'] = ['Add language', 'Add an additional target language to this site root'];
$GLOBALS['TL_LANG']['tl_inline_language']['edit'] = ['Edit language', 'Edit language ID %s'];
$GLOBALS['TL_LANG']['tl_inline_language']['delete'] = ['Delete language', 'Delete language ID %s'];
$GLOBALS['TL_LANG']['tl_inline_language']['toggle'] = ['Toggle visibility', 'Toggle visibility of language ID %s'];
$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationMode'] = [
    'Content structure mode',
    'Connected translation: the translated language follows the source article and content-element structure. Editors translate fields while type, position, order and relationships remain connected to the source. Free language content: the translated language has an independent article and content-element structure and may differ completely from the source language.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationModes'] = [
    'connected' => 'Connected translation',
    'free' => 'Free language content',
];
$GLOBALS['TL_LANG']['tl_inline_language']['contentFallbackMode'] = [
    'Content translation mode',
    'Controls how untranslated content is rendered in this language.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['contentFallbackModes'] = [
    'strict' => 'Do not show content without translation',
    'fallback' => 'Show default content when no translation exists',
];
$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationModeConfirm'] = [
    'Confirm content mode change',
    'Confirm that stored content of the other mode stops rendering. No data is deleted.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['defaultSourceLanguage'] = 'Default/source language';
$GLOBALS['TL_LANG']['tl_inline_language']['targetLanguage'] = 'Target language';
$GLOBALS['TL_LANG']['tl_inline_language']['active'] = 'Active';

$GLOBALS['TL_LANG']['tl_inline_language']['url_legend'] = 'Language URL';
$GLOBALS['TL_LANG']['tl_inline_language']['urlProtocol'] = [
    'Protocol',
    'Select a fixed protocol or inherit the protocol configured for the website root.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['urlProtocols'] = [
    '' => 'Inherit from website root',
    'https' => 'HTTPS',
    'http' => 'HTTP',
];
$GLOBALS['TL_LANG']['tl_inline_language']['urlDomain'] = [
    'Domain',
    'Optional. Leave empty to use the website root domain. Enter only a hostname, for example www.example.de.',
];
$GLOBALS['TL_LANG']['tl_inline_language']['urlEntryPoint'] = [
    'Entry point',
    'Optional language path prefix, for example /de. Use / for the domain root.',
];
