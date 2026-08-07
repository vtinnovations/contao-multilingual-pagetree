<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


\Contao\System::loadLanguageFile('tl_content', 'en');
if (isset($GLOBALS['TL_LANG']['tl_content']) && is_array($GLOBALS['TL_LANG']['tl_content'])) {
    $GLOBALS['TL_LANG']['tl_content_translation'] = array_merge(
        $GLOBALS['TL_LANG']['tl_content'],
        $GLOBALS['TL_LANG']['tl_content_translation'] ?? []
    );
}

$GLOBALS['TL_LANG']['tl_content_translation']['language']       = ['Language', 'Please select the language for this translation.'];
$GLOBALS['TL_LANG']['tl_content_translation']['headline_value'] = ['Headline text', 'Enter the translated headline text. Leave empty to keep the original.'];
$GLOBALS['TL_LANG']['tl_content_translation']['headline_unit']  = ['Headline level', 'Optionally change the heading level (h1–h6). Leave empty to keep the original.'];
$GLOBALS['TL_LANG']['tl_content_translation']['text']           = ['Text', 'You can use the HTML editor to format the text.'];
$GLOBALS['TL_LANG']['tl_content_translation']['html']           = ['HTML code', 'You can modify the list of allowed HTML tags in the back end settings.'];
$GLOBALS['TL_LANG']['tl_content_translation']['code']           = ['Code', 'Note that the code will not be executed. Use FCE to do so.'];
$GLOBALS['TL_LANG']['tl_content_translation']['linkTitle']      = ['Link title', 'The link title will be displayed instead of the target URL.'];
$GLOBALS['TL_LANG']['tl_content_translation']['titleText']      = ['Link text', 'The link text will be displayed if the image cannot be loaded.'];
$GLOBALS['TL_LANG']['tl_content_translation']['caption']        = ['Image caption', 'Here you can enter a short text that will be displayed below the image.'];
$GLOBALS['TL_LANG']['tl_content_translation']['alt']            = ['Alternate text', 'An accessible alternate text for the image.'];
$GLOBALS['TL_LANG']['tl_content_translation']['imageTitle']     = ['Image title', 'Here you can add a title for the image.'];
$GLOBALS['TL_LANG']['tl_content_translation']['url']            = ['Target URL', 'Please enter the web address (http://...) or a file path.'];
$GLOBALS['TL_LANG']['tl_content_translation']['playerCaption']  = ['Player caption', 'Here you can enter a caption for the media player.'];
$GLOBALS['TL_LANG']['tl_content_translation']['listitems']      = ['List items', 'Here you can manage the list items.'];
$GLOBALS['TL_LANG']['tl_content_translation']['tableitems']     = ['Table items', 'Here you can manage the table items.'];
$GLOBALS['TL_LANG']['tl_content_translation']['summary']        = ['Table summary', 'Please enter a short summary of the table and describe its purpose or structure.'];
$GLOBALS['TL_LANG']['tl_content_translation']['data']           = ['Description list terms & values', 'Translate the key/value pairs of the description list.'];

$GLOBALS['TL_LANG']['tl_content_translation']['language_legend'] = 'Language';
$GLOBALS['TL_LANG']['tl_content_translation']['content_legend']  = 'Translated content';
$GLOBALS['TL_LANG']['tl_content_translation']['title_legend']    = 'Title and type';
$GLOBALS['TL_LANG']['tl_content_translation']['type_legend']     = 'Element type';
$GLOBALS['TL_LANG']['tl_content_translation']['text_legend']     = 'Text / HTML / Code';
$GLOBALS['TL_LANG']['tl_content_translation']['headline_legend'] = 'Headline';
$GLOBALS['TL_LANG']['tl_content_translation']['image_legend']    = 'Image settings';
$GLOBALS['TL_LANG']['tl_content_translation']['media_legend']    = 'Media settings';
$GLOBALS['TL_LANG']['tl_content_translation']['link_legend']     = 'Link settings';
$GLOBALS['TL_LANG']['tl_content_translation']['list_legend']     = 'List items';
$GLOBALS['TL_LANG']['tl_content_translation']['table_legend']    = 'Table items';
$GLOBALS['TL_LANG']['tl_content_translation']['template_legend'] = 'Template settings';
$GLOBALS['TL_LANG']['tl_content_translation']['publish_legend']  = 'Publish settings';
$GLOBALS['TL_LANG']['tl_content_translation']['expert_legend']   = 'Expert settings';

$GLOBALS['TL_LANG']['tl_content_translation']['new']    = ['New translation', 'Create a new translation'];
$GLOBALS['TL_LANG']['tl_content_translation']['edit']   = ['Edit translation', 'Edit translation ID %s'];
$GLOBALS['TL_LANG']['tl_content_translation']['delete'] = ['Delete translation', 'Delete translation ID %s'];
