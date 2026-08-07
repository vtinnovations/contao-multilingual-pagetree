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
use Contao\LayoutModel;
use Contao\PageModel;
use Contao\PageRegular;
use Contao\StringUtil;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\LanguageMetadata;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\LanguageMetadataBuilder;

/**
 * Emits the canonical and hreflang metadata of the current resource.
 *
 * The "generatePage" hook runs after the frontend modules have been generated,
 * so a translated detail canonical replaces the one a news, event or FAQ reader
 * derived from the source record. The metadata does not depend on a language
 * switcher module being present on the page.
 */
#[AsHook('generatePage')]
class LanguageMetadataListener
{
    private const HEAD_KEY_PREFIX = 'contao_multilingual_pagetree_alternate_';
    private const HEAD_KEY_CANONICAL = 'contao_multilingual_pagetree_canonical';

    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly LanguageMetadataBuilder $metadataBuilder,
        private readonly RequestStack $requestStack,
        private readonly ?ResponseContextAccessor $responseContextAccessor = null,
    ) {
    }

    public function __invoke(PageModel $pageModel, LayoutModel $layout, PageRegular $pageRegular): void
    {
        if (!$this->languageHelper->isFrontendRequest()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return;
        }

        $metadata = $this->metadataBuilder->build($request, $pageModel);
        $headBag = $this->headBag();

        $this->emitCanonical($metadata, $headBag);
        $this->emitAlternates($metadata, $headBag);
    }

    private function emitCanonical(LanguageMetadata $metadata, ?HtmlHeadBag $headBag): void
    {
        if (null === $metadata->canonicalUrl) {
            return;
        }

        // The head bag owns Contao's canonical value; its setter replaces the
        // existing one instead of adding a second tag.
        if (null !== $headBag && method_exists($headBag, 'setCanonicalUri')) {
            $headBag->setCanonicalUri($metadata->canonicalUrl);

            return;
        }

        foreach (($GLOBALS['TL_HEAD'] ?? []) as $key => $tag) {
            if (self::HEAD_KEY_CANONICAL !== $key && is_string($tag) && preg_match('/rel=["\']canonical["\']/i', $tag)) {
                return;
            }
        }

        $GLOBALS['TL_HEAD'][self::HEAD_KEY_CANONICAL] = sprintf(
            '<link rel="canonical" href="%s">',
            StringUtil::specialchars($metadata->canonicalUrl),
        );
    }

    private function emitAlternates(LanguageMetadata $metadata, ?HtmlHeadBag $headBag): void
    {
        foreach ($metadata->links() as $link) {
            if (null !== $headBag && method_exists($headBag, 'addTag')) {
                $headBag->addTag('link', ['rel' => 'alternate', 'hreflang' => $link['hreflang'], 'href' => $link['href']]);

                continue;
            }

            // A keyed entry cannot be emitted twice, even if the hook runs again.
            $GLOBALS['TL_HEAD'][self::HEAD_KEY_PREFIX.$link['hreflang']] = sprintf(
                '<link rel="alternate" hreflang="%s" href="%s">',
                StringUtil::specialchars($link['hreflang']),
                StringUtil::specialchars($link['href']),
            );
        }
    }

    private function headBag(): ?HtmlHeadBag
    {
        try {
            $responseContext = $this->responseContextAccessor?->getResponseContext();

            if (null !== $responseContext && $responseContext->has(HtmlHeadBag::class)) {
                return $responseContext->get(HtmlHeadBag::class);
            }
        } catch (\Throwable) {
            // Metadata must never break the page.
        }

        return null;
    }
}
