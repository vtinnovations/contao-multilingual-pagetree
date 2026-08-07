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
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentFallbackMode;
use Vtinnovations\ContaoMultilingualPagetree\Availability\SiteLanguageRegistryInterface;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentModeContext;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Translation\RecordIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ScopedModelOverlay;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordLocatorInterface;

/**
 * Applies content element translations before Contao renders the element.
 *
 * Contao\Controller::getContentElement() calls isVisibleElement() on the record
 * it is about to render, and it does so before it decides whether the element is
 * a fragment controller or a legacy ContentElement class. Overlaying the record
 * in that hook therefore reaches both rendering paths, third party elements
 * included, and Contao afterwards renders the element exactly once through its
 * own pipeline. This bundle never instantiates a content element itself.
 */
class ContentTranslationListener
{
    private const SOURCE_TABLE = 'tl_content';
    private const TRANSLATION_TABLE = 'tl_content_translation';
    private const ARTICLE_TRANSLATION_TABLE = 'tl_article_translation';

    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly TranslationOverlayBuilder $overlayBuilder,
        private readonly ScopedModelOverlay $scopedOverlay,
        private readonly TranslationRecordLocatorInterface $translationLocator,
        private readonly ?ContentModeContext $contentMode = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?SiteLanguageRegistryInterface $siteLanguages = null,
    ) {
    }

    /**
     * Pre-render hook: decides the visibility for the active language and puts
     * the translated values on the record Contao is going to render.
     */
    #[AsHook('isVisibleElement')]
    public function onIsVisibleElement(object $element, bool $isVisible): bool
    {
        if (!$isVisible || self::SOURCE_TABLE !== RecordIdentity::table($element)) {
            return $isVisible;
        }

        try {
            if (!$this->languageHelper->isFrontendRequest()) {
                return $isVisible;
            }

            // Point 9: exactly one content tree renders per language. A record
            // that does not belong to the active tree is never rendered, and
            // free records never receive a connected overlay.
            if (!$this->isRenderable($element)) {
                return false;
            }

            $language = $this->languageHelper->getActiveLanguage();

            if ($this->languageHelper->isDefaultLanguage($language) || !$this->usesConnectedOverlay($element)) {
                return $isVisible;
            }

            $elementId = RecordIdentity::id($element);

            if ($elementId <= 0) {
                return $isVisible;
            }

            if (!$this->isParentArticleAvailable($element, $language)) {
                return false;
            }

            $translation = $this->translationLocator->find(
                self::TRANSLATION_TABLE,
                $elementId,
                $language,
                $this->parentId($element),
            );

            // The language's canonical content policy decides what an
            // untranslated element does independently of page availability.
            $fallbackMode = $this->fallbackMode($language);

            if (null === $translation) {
                return $fallbackMode->showsSourceWhenMissing() && $isVisible;
            }

            // Publication of the translated element is independent from the
            // source element, which Contao has already checked at this point.
            if (!$this->languageHelper->isPublished($translation)) {
                return false;
            }

            $this->scopedOverlay->apply(
                $element,
                $this->overlayBuilder->buildRow($element, $translation, self::TRANSLATION_TABLE, $language),
            );
        } catch (\Throwable $exception) {
            // A broken translation must never break the frontend: release any
            // partial overlay and let Contao render the source record.
            $this->scopedOverlay->restore($element);
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: could not overlay content element %d: %s', RecordIdentity::id($element), $exception->getMessage()),
            );

            return $isVisible;
        }

        return $isVisible;
    }

    /**
     * Post-render hook: releases the temporary overlay so nothing rendered later
     * in the same request can observe translated values. The buffer Contao
     * produced is returned untouched - it is never replaced or regenerated.
     */
    #[AsHook('getContentElement')]
    public function onGetContentElement(object $element, string $buffer, mixed $contentElement = null): string
    {
        $this->scopedOverlay->restore($element);

        return $buffer;
    }

    /**
     * Content elements of an article that is not published in the active
     * language must not be rendered. Nested elements (ptable "tl_content") are
     * covered by their parent element, which Contao renders recursively.
     */
    private function isParentArticleAvailable(object $element, string $language): bool
    {
        $parentTable = (string) ($element->ptable ?? '');

        if ('' !== $parentTable && 'tl_article' !== $parentTable) {
            return true;
        }

        $parentId = $this->parentId($element);

        if ($parentId <= 0) {
            return true;
        }

        $articleTranslation = $this->translationLocator->find(self::ARTICLE_TRANSLATION_TABLE, $parentId, $language);

        return null === $articleTranslation || $this->languageHelper->isPublished($articleTranslation);
    }

    /**
     * The configured rule of the active language for the root that owns this
     * element. Without the registry the previous fallback behaviour applies.
     */
    private function fallbackMode(string $language): ContentFallbackMode
    {
        if (null === $this->siteLanguages) {
            return ContentFallbackMode::Fallback;
        }

        $rootId = $this->languageHelper->getRootPageId();

        return $rootId > 0
            ? $this->siteLanguages->contentFallbackMode($rootId, $language)
            : ContentFallbackMode::Fallback;
    }

    private function parentId(object $element): int
    {
        $pid = $element->pid ?? null;

        return is_numeric($pid) ? (int) $pid : 0;
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
