<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


declare(strict_types=1);

/*
 * Allow tables in core modules
 *
 * tl_content_translation is deliberately absent: it is storage, not a backend
 * table. Additional-language content is edited through the native tl_content
 * form.
 *
 * Compatibility: tl_inline_language and the tl_*_translation tables are
 * intentionally retained so existing persisted data remains available.
 */
$GLOBALS['BE_MOD']['content']['page']['tables'][] = 'tl_inline_language';
$GLOBALS['BE_MOD']['content']['page']['tables'][] = 'tl_page_translation';
$GLOBALS['BE_MOD']['content']['article']['tables'][] = 'tl_article_translation';

if (isset($GLOBALS['BE_MOD']['content']['faq'])) {
    $GLOBALS['BE_MOD']['content']['faq']['tables'][] = 'tl_faq_translation';
}
if (isset($GLOBALS['BE_MOD']['content']['news'])) {
    $GLOBALS['BE_MOD']['content']['news']['tables'][] = 'tl_news_translation';
}
if (isset($GLOBALS['BE_MOD']['content']['calendar'])) {
    $GLOBALS['BE_MOD']['content']['calendar']['tables'][] = 'tl_calendar_events_translation';
}

/*
 * Models
 */
$GLOBALS['TL_MODELS']['tl_inline_language'] = 'Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel';
$GLOBALS['TL_MODELS']['tl_content_translation'] = 'Vtinnovations\ContaoMultilingualPagetree\Model\ContentTranslationModel';
$GLOBALS['TL_MODELS']['tl_article_translation'] = 'Vtinnovations\ContaoMultilingualPagetree\Model\ArticleTranslationModel';
$GLOBALS['TL_MODELS']['tl_page_translation'] = 'Vtinnovations\ContaoMultilingualPagetree\Model\PageTranslationModel';
$GLOBALS['TL_MODELS']['tl_news_translation'] = 'Vtinnovations\ContaoMultilingualPagetree\Model\NewsTranslationModel';
$GLOBALS['TL_MODELS']['tl_calendar_events_translation'] = 'Vtinnovations\ContaoMultilingualPagetree\Model\CalendarEventsTranslationModel';
$GLOBALS['TL_MODELS']['tl_faq_translation'] = 'Vtinnovations\ContaoMultilingualPagetree\Model\FaqTranslationModel';

/*
 * Frontend modules are registered via #[AsFrontendModule] attribute in Contao 5.3
 * Hooks are registered via #[AsHook] attribute
 */
