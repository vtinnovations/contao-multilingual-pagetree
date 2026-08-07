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
use Psr\Log\LoggerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentModeContext;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Translation\RecordIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ScopedModelOverlay;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordLocatorInterface;

/**
 * Applies article translations before Contao renders the article.
 *
 * Contao\Controller::getArticle() triggers the "getArticle" hook on the article
 * row right before it creates the ModuleArticle that renders the article and its
 * content elements. Overlaying the row there means the normal article pipeline -
 * article template, column handling, element order, nested elements and access
 * checks - stays completely untouched. The article content itself is never
 * queried, concatenated or re-rendered by this bundle.
 */
class ArticleTranslationListener
{
    private const SOURCE_TABLE = 'tl_article';
    private const TRANSLATION_TABLE = 'tl_article_translation';

    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly TranslationOverlayBuilder $overlayBuilder,
        private readonly ScopedModelOverlay $scopedOverlay,
        private readonly TranslationRecordLocatorInterface $translationLocator,
        private readonly ?ContentModeContext $contentMode = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Pre-render hook for the article paths on which Contao checks visibility
     * (insert tags, article list, "articles" parameter).
     */
    #[AsHook('isVisibleElement')]
    public function onIsVisibleElement(object $element, bool $isVisible): bool
    {
        if (!$isVisible || self::SOURCE_TABLE !== RecordIdentity::table($element)) {
            return $isVisible;
        }

        // Point 9: only the article tree of the active content mode renders.
        if (!$this->isRenderable($element)) {
            return false;
        }

        try {
            $translation = $this->findTranslation($element);
        } catch (\Throwable $exception) {
            // Never hide an article because of a broken translation lookup.
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: could not read the translation of article %d: %s', RecordIdentity::id($element), $exception->getMessage()),
            );

            return $isVisible;
        }

        if (null === $translation) {
            return $isVisible;
        }

        return $this->languageHelper->isPublished($translation);
    }

    /**
     * Pre-render hook: puts the translated article fields on the article row
     * before Contao builds the article module from it.
     */
    #[AsHook('getArticle')]
    public function onGetArticle(object $article, mixed ...$arguments): void
    {
        try {
            // An article outside the active content tree is suppressed instead
            // of translated; its content elements are suppressed as well.
            if (!$this->isRenderable($article)) {
                $this->scopedOverlay->apply(
                    $article,
                    $this->hiddenArticleRow($article),
                    $this->token(RecordIdentity::id($article)),
                );

                return;
            }

            $translation = $this->usesConnectedOverlay($article)
                ? $this->findTranslation($article)
                : null;

            if (null === $translation) {
                return;
            }

            $articleId = RecordIdentity::id($article);
            $language = $this->languageHelper->getActiveLanguage();

            if ($this->languageHelper->isPublished($translation)) {
                $row = $this->overlayBuilder->buildRow($article, $translation, self::TRANSLATION_TABLE, $language);

                // Contao copies the article title into "headline" right before
                // this hook, so the translated title has to follow it.
                if (array_key_exists('headline', $row) && array_key_exists('title', $row)) {
                    $row['headline'] = $row['title'];
                }
            } else {
                $row = $this->hiddenArticleRow($article);
            }

            $this->scopedOverlay->apply($article, $row, $this->token($articleId));
        } catch (\Throwable $exception) {
            $this->scopedOverlay->restore($article);
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: could not overlay article %d: %s', RecordIdentity::id($article), $exception->getMessage()),
            );
        }
    }

    /**
     * Post-render hook: releases the article overlay. Contao has rendered the
     * article and its content elements exactly once at this point; neither the
     * template nor the rendered elements are modified here.
     *
     * Contao passes a FrontendTemplate and a Module; the parameters are typed
     * loosely so any caller of the hook is accepted.
     *
     * @param array<string, mixed> $data
     */
    #[AsHook('compileArticle')]
    public function onCompileArticle(object $template, array $data, object $module): void
    {
        $articleId = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;

        if ($articleId > 0) {
            $this->scopedOverlay->restoreToken($this->token($articleId));
        }
    }

    private function findTranslation(object $article): ?object
    {
        if (!$this->languageHelper->isFrontendRequest()) {
            return null;
        }

        $language = $this->languageHelper->getActiveLanguage();

        if ($this->languageHelper->isDefaultLanguage($language)) {
            return null;
        }

        $articleId = RecordIdentity::id($article);

        if ($articleId <= 0) {
            return null;
        }

        return $this->translationLocator->find(self::TRANSLATION_TABLE, $articleId, $language);
    }

    /**
     * An article that is not published in the active language keeps its record
     * identity but loses its translated headline. Its content elements are
     * skipped by the content element listener, so nothing is rendered twice and
     * nothing is rendered that should stay hidden.
     *
     * @return array<string, mixed>
     */
    private function hiddenArticleRow(object $article): array
    {
        $row = $this->overlayBuilder->buildRow($article, null, self::TRANSLATION_TABLE);
        $row['published'] = '';
        $row['invisible'] = '1';

        foreach (['title', 'headline'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = '';
            }
        }

        return $row;
    }

    private function token(int $articleId): string
    {
        return self::SOURCE_TABLE.':'.$articleId;
    }

    /**
     * Point 9: a record only renders when it belongs to the content tree of the
     * active language. Without the context service the source tree renders,
     * which is the pre point 9 behaviour.
     */
    private function isRenderable(object $record): bool
    {
        return null === $this->contentMode || $this->contentMode->isRenderable($record);
    }

    /**
     * Connected field-state overlays apply to source records of a connected
     * language only; free records are independent content.
     */
    private function usesConnectedOverlay(object $record): bool
    {
        return null === $this->contentMode || $this->contentMode->usesConnectedOverlay($record);
    }
}
