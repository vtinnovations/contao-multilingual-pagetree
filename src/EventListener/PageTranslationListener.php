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
use Contao\LayoutModel;
use Contao\PageModel;
use Contao\PageRegular;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Psr\Log\LoggerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResult;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

#[AsHook('getPageLayout')]
#[AsHook('loadPageDetails', method: 'onLoadPageDetails')]
class PageTranslationListener
{
    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly ?ResponseContextAccessor $responseContextAccessor,
        private readonly TranslationOverlayResolver $overlayResolver,
        private readonly TranslationFieldRegistry $fieldRegistry,
        private readonly PageAvailabilityResolver $availabilityResolver,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onLoadPageDetails(array $parentModels, PageModel $pageModel): void
    {
        if (!$this->languageHelper->isFrontendRequest()) {
            return;
        }

        $activeLanguage = $this->languageHelper->getActiveLanguage();
        if (!$activeLanguage || $this->languageHelper->isDefaultLanguage()) {
            return;
        }

        // Always keep the urlPrefix and language aligned with active language across frontend navigation links
        $pageModel->urlPrefix = $activeLanguage;
        $pageModel->language = $activeLanguage;
        if (isset($pageModel->rootLanguage)) {
            $pageModel->rootLanguage = $activeLanguage;
        }

        // CRITICAL: Contao sets $GLOBALS['TL_LANGUAGE'] before this hook based on the root page.
        // We must override it here so that ALL frontend modules (News, Calendar, FAQ) load the correct labels.
        if (isset($GLOBALS['TL_LANGUAGE']) && $GLOBALS['TL_LANGUAGE'] !== $activeLanguage) {
            $GLOBALS['TL_LANGUAGE'] = $activeLanguage;

            // Force reload core language files so any already loaded German labels are overwritten
            if (class_exists(\Contao\System::class)) {
                \Contao\System::loadLanguageFile('default', $activeLanguage, true);
                \Contao\System::loadLanguageFile('modules', $activeLanguage, true);
            }
        }

        $availability = $this->availabilityResolver->resolve($pageModel, $activeLanguage);

        // loadDetails() also runs for unrelated pages (navigation, insert tags).
        // Only the page of the current request may end the request.
        if ($availability->isUnavailable() && $this->isCurrentPage($pageModel)) {
            $this->pageUnavailable($availability);
        }

        if ($availability->isTranslated() && null !== $availability->overlayTranslation()) {
            $this->applyPageOverlay($pageModel, $availability->overlayTranslation());
        }
    }

    public function __invoke(PageModel $pageModel, LayoutModel $layout, PageRegular $pageRegular): void
    {
        if (!$this->languageHelper->isFrontendRequest()) {
            return;
        }

        $headBag = null;
        $responseContext = $this->responseContextAccessor?->getResponseContext();
        if ($responseContext && $responseContext->has(HtmlHeadBag::class)) {
            $headBag = $responseContext->get(HtmlHeadBag::class);
        }

        // Canonical and hreflang metadata is emitted by LanguageMetadataListener
        // on the "generatePage" hook, after the detail readers have run.

        $activeLanguage = $this->languageHelper->getActiveLanguage();
        if ($this->languageHelper->isDefaultLanguage()) {
            return;
        }

        $availability = $this->availabilityResolver->resolve($pageModel, $activeLanguage);

        if ($availability->isUnavailable()) {
            $this->pageUnavailable($availability);
        }

        // A fallback page renders the unmodified source page while the requested
        // language stays active; no synthetic translation record is created.
        if (!$availability->isTranslated() || null === $availability->overlayTranslation()) {
            return;
        }

        $resolved = $this->applyPageOverlay($pageModel, $availability->overlayTranslation());

        if ($headBag !== null) {
            $headBag->setTitle((string) $resolved['pageTitle']);
        }

        if ($headBag !== null) {
            $headBag->setMetaDescription((string) $resolved['description']);
        }
    }

    /**
     * Ends the request with Contao's normal not-found handling. The diagnostic
     * reason is logged, never shown to the visitor.
     */
    private function pageUnavailable(PageAvailabilityResult $availability): never
    {
        $this->logger?->info(sprintf(
            'Contao Multilingual Pagetree: page %d is unavailable in "%s" (mode "%s", reason "%s").',
            (int) $availability->sourcePage->id,
            $availability->targetLanguage,
            $availability->mode->value,
            $availability->reason->value,
        ));

        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('The requested page is not available in this language.');
    }

    private function isCurrentPage(PageModel $pageModel): bool
    {
        $currentPage = $this->languageHelper->getCurrentPageModel();

        return null !== $currentPage && (int) $currentPage->id === (int) $pageModel->id;
    }

    private function applyPageOverlay(PageModel $pageModel, object $translation): array
    {
        $sourceRow = $this->preserveSourceAlias($pageModel);
        $resolved = [];
        foreach ($this->fieldRegistry->fieldNames('tl_page_translation') as $field) {
            $resolved[$field] = $this->overlayResolver->resolveField(
                $sourceRow,
                $translation,
                $field,
                'tl_page_translation',
            );
            $pageModel->$field = $resolved[$field];
            if (isset($GLOBALS['objPage']) && (int) $GLOBALS['objPage']->id === (int) $pageModel->id) {
                $GLOBALS['objPage']->$field = $resolved[$field];
            }
        }

        return $resolved;
    }

    /**
     * Keeps the untouched source alias reachable after the overlay replaced the
     * alias of the current page model, so canonical, fallback and default
     * language URLs keep resolving against the real source record.
     *
     * @return array<string, mixed>
     */
    private function preserveSourceAlias(PageModel $pageModel): array
    {
        $property = PageAvailabilityResolver::SOURCE_ALIAS_PROPERTY;
        $preserved = $pageModel->$property ?? null;

        if (!is_string($preserved) || '' === $preserved) {
            $preserved = (string) $pageModel->alias;
            $pageModel->$property = $preserved;

            if (isset($GLOBALS['objPage']) && (int) $GLOBALS['objPage']->id === (int) $pageModel->id) {
                $GLOBALS['objPage']->$property = $preserved;
            }
        }

        $sourceRow = $pageModel->row();
        $sourceRow['alias'] = $preserved;

        return $sourceRow;
    }
}
