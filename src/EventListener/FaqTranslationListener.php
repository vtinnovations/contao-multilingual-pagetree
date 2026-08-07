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

namespace Vtinnovations\ContaoMultilingualPagetree\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Template;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Model\FaqTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

#[AsHook('parseTemplate')]
class FaqTranslationListener
{
    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly TranslationOverlayResolver $overlayResolver,
    ) {
    }

    public function __invoke(Template $template): void
    {
        if (!$this->languageHelper->isFrontendRequest() || $this->languageHelper->isDefaultLanguage()) {
            return;
        }

        $templateName = $template->getName();
        $activeLanguage = $this->languageHelper->getActiveLanguage();

        // 1. Handle individual FAQ templates (faq_default, etc.)
        if (str_starts_with($templateName, 'faq_')) {
            $rawId = (string) ($template->id ?? '');
            if (preg_match('/(?:^|_)(\d+)$/', $rawId, $matches)) {
                $faqId = (int) $matches[1];
            } else {
                $faqId = (int) $rawId;
            }

            if ($faqId) {
                $translation = FaqTranslationModel::findByPidAndLanguage($faqId, $activeLanguage);
                if ($translation !== null) {
                    if (!$this->languageHelper->isPublished($translation)) {
                        $template->question = '';
                        $template->answer = '';
                        return;
                    }
                    $question = $this->overlayResolver->resolveField($template, $translation, 'question', 'tl_faq_translation');
                    $template->question = $question;
                    $template->title = $question;
                    $template->headline = $question;
                    $template->answer = $this->overlayResolver->resolveField($template, $translation, 'answer', 'tl_faq_translation');
                }
            }
            return;
        }

        // 2. Handle module templates (mod_faqlist, mod_faqpage, mod_faqreader)
        if (str_starts_with($templateName, 'mod_faq')) {

            // Handle lists (mod_faqlist, mod_faqpage) where FAQs are in an array
            if (isset($template->faq) && is_array($template->faq)) {
                $faqData = $template->faq;
                foreach ($faqData as $categoryId => &$categoryData) {
                    // Contao groups FAQs by category in mod_faqlist. The actual FAQs are in $categoryData['items']
                    if (is_array($categoryData) && isset($categoryData['items']) && is_array($categoryData['items'])) {
                        foreach ($categoryData['items'] as $key => &$item) {
                            $itemId = (int) ($item['id'] ?? 0);
                            if ($itemId) {
                                $translation = FaqTranslationModel::findByPidAndLanguage($itemId, $activeLanguage);
                                if ($translation !== null) {
                                    if (!$this->languageHelper->isPublished($translation)) {
                                        unset($categoryData['items'][$key]); // Remove if unpublished
                                        continue;
                                    }
                                    $sourceItem = $item;
                                    $item['question'] = $this->overlayResolver->resolveField($sourceItem, $translation, 'question', 'tl_faq_translation');
                                    $item['answer'] = $this->overlayResolver->resolveField($sourceItem, $translation, 'answer', 'tl_faq_translation');
                                    $alias = $this->overlayResolver->resolveField($sourceItem, $translation, 'alias', 'tl_faq_translation');
                                    if ($alias !== ($sourceItem['alias'] ?? null)) {
                                        if (isset($item['href']) && $item['alias']) {
                                            $item['href'] = preg_replace(
                                                '/(\/|items=)' . preg_quote($item['alias'], '/') . '($|&|\?)/',
                                                '$1'.$alias.'$2',
                                                $item['href']
                                            );
                                        }
                                        $item['alias'] = $alias;
                                    }
                                }
                            }
                        }
                        // Re-index array in case items were unset
                        $categoryData['items'] = array_values($categoryData['items']);
                    }
                }
                $template->faq = $faqData;
            }

            // Handle reader (mod_faqreader) where properties are directly on the template
            if (isset($template->question) && isset($template->answer) && !isset($template->faq)) {
                // Determine ID from URL alias or template properties
                $faqId = 0;
                $alias = \Contao\Input::get('auto_item') ?: \Contao\Input::get('items');
                if ($alias && is_string($alias)) {
                    $item = \Contao\FaqModel::findByIdOrAlias($alias);
                    if ($item) {
                        $faqId = (int) $item->id;
                    }
                }

                // Fallback to template ID (if it's the numeric ID)
                if (!$faqId) {
                    $rawId = (string) ($template->id ?? '');
                    if (preg_match('/(?:^|_)(\d+)$/', $rawId, $matches)) {
                        $faqId = (int) $matches[1];
                    } elseif (is_numeric($rawId)) {
                        $faqId = (int) $rawId;
                    }
                }

                if ($faqId) {
                    $translation = FaqTranslationModel::findByPidAndLanguage($faqId, $activeLanguage);
                    if ($translation !== null) {
                        if (!$this->languageHelper->isPublished($translation)) {
                            $template->question = '';
                            $template->answer = '';
                            return;
                        }
                        $question = $this->overlayResolver->resolveField($template, $translation, 'question', 'tl_faq_translation');
                        $template->question = $question;
                        $template->title = $question;
                        $template->headline = $question;
                        $template->answer = $this->overlayResolver->resolveField($template, $translation, 'answer', 'tl_faq_translation');
                    }
                }
            }
        }
    }
}
