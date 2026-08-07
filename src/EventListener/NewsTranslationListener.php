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
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\FrontendTemplate;
use Contao\Module;
use Contao\System;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Model\NewsTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

#[AsHook('parseArticles')]
class NewsTranslationListener
{
    private LanguageHelper $languageHelper;
    private ?ResponseContextAccessor $responseContextAccessor;

    public function __construct(
        LanguageHelper $languageHelper,
        ?ResponseContextAccessor $responseContextAccessor = null,
        ?TranslationOverlayResolver $overlayResolver = null,
    ) {
        $this->languageHelper = $languageHelper;
        $this->responseContextAccessor = $responseContextAccessor ?? System::getContainer()->get('contao.routing.response_context_accessor', System::NULL_ON_INVALID_REFERENCE);
        $this->overlayResolver = $overlayResolver ?? System::getContainer()->get(TranslationOverlayResolver::class);
    }

    private TranslationOverlayResolver $overlayResolver;

    public function __invoke(FrontendTemplate $template, array $newsRow, Module $module): void
    {
        if (!$this->languageHelper->isFrontendRequest()) {
            return;
        }

        $activeLanguage = $this->languageHelper->getActiveLanguage();

        if ($this->languageHelper->isDefaultLanguage()) {
            return;
        }

        if (empty($newsRow['id'])) {
            return;
        }

        $newsId = (int) $newsRow['id'];
        $translation = NewsTranslationModel::findByPidAndLanguage($newsId, $activeLanguage);

        if ($translation !== null) {
            // Check publication state
            if (!$this->languageHelper->isPublished($translation)) {
                $template->headline = '';
                $template->teaser = '';
                $template->hasTeaser = false;
                $template->text = '';
                return;
            }

            \Contao\System::loadLanguageFile('default', $activeLanguage);

            $originalHeadline = $newsRow['headline'] ?? '';
            $headline = $this->overlayResolver->resolveField($newsRow, $translation, 'headline', 'tl_news_translation');
            $subheadline = $this->overlayResolver->resolveField($newsRow, $translation, 'subheadline', 'tl_news_translation');
            $teaser = $this->overlayResolver->resolveField($newsRow, $translation, 'teaser', 'tl_news_translation');
            $text = $this->overlayResolver->resolveField($newsRow, $translation, 'text', 'tl_news_translation');
            $alias = $this->overlayResolver->resolveField($newsRow, $translation, 'alias', 'tl_news_translation');
            $pageTitle = $this->overlayResolver->resolveField($newsRow, $translation, 'pageTitle', 'tl_news_translation');
            $description = $this->overlayResolver->resolveField($newsRow, $translation, 'description', 'tl_news_translation');

            $template->headline = $headline;
            $template->newsTitle = $headline;
            $template->newsHeadline = $headline;
            $template->title = $headline;

                if ($originalHeadline !== '' && $originalHeadline !== $headline) {
                    $arrData = $template->getData();
                    foreach ($arrData as $k => $v) {
                        if (is_string($v) && strpos($v, $originalHeadline) !== false) {
                            $template->$k = str_replace($originalHeadline, (string) $headline, $v);
                        }
                    }
                }

                // Update linkHeadline if it contains an anchor tag
                if (isset($template->linkHeadline)) {
                    $template->linkHeadline = preg_replace(
                        '/(<a[^>]*>)(.*?)(<\/a>)/is',
                        '$1'.$headline.'$3',
                        $template->linkHeadline
                    );
                    $template->linkHeadline = preg_replace(
                        '/(title=")(.*?)(")/i',
                        '$1'.\Contao\StringUtil::specialchars((string) $headline).'$3',
                        $template->linkHeadline
                    );
                }
            $template->subheadline = $subheadline;
            $template->teaser = $teaser;
            $template->hasTeaser = $teaser !== '' && $teaser !== null;
            $template->text = $text;

            // The "Go back" labels and author data are now natively translated by Contao
            // since PageTranslationListener overrides the global language for the request.

            // Replace Alias in Links
            if ($alias !== ($newsRow['alias'] ?? null)) {
                $oldAlias = $newsRow['alias'];
                $newAlias = (string) $alias;

                // Regex matches /oldAlias or items=oldAlias followed by end of string, query params, or quote marks.
                $regex = '/(\/|items=)' . preg_quote($oldAlias, '/') . '([?&"\'<]|$)/';

                if (isset($template->link)) {
                    $template->link = preg_replace($regex, '$1' . $newAlias . '$2', $template->link);
                }

                if (isset($template->linkHeadline)) {
                    $template->linkHeadline = preg_replace($regex, '$1' . $newAlias . '$2', $template->linkHeadline);
                }

                if (isset($template->more)) {
                    $template->more = preg_replace($regex, '$1' . $newAlias . '$2', $template->more);
                }
            }

            if (isset($GLOBALS['objPage']) && $GLOBALS['objPage'] instanceof \Contao\PageModel) {
                $GLOBALS['objPage']->pageTitle = $pageTitle;
            }

            if ($this->responseContextAccessor) {
                $responseContext = $this->responseContextAccessor->getResponseContext();
                if ($responseContext && $responseContext->has(HtmlHeadBag::class)) {
                    $headBag = $responseContext->get(HtmlHeadBag::class);
                    $headBag->setTitle((string) $pageTitle);
                    $headBag->setMetaDescription((string) $description);
                }
            }
        }
    }
}
