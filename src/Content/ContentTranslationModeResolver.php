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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

use Contao\PageModel;
use Vtinnovations\ContaoMultilingualPagetree\Availability\SiteLanguageRegistryInterface;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;

/**
 * The single authority for "which content localisation strategy applies to this
 * page in this language?".
 *
 * The answer depends only on the root site and the configured language; it never
 * depends on a browser header, a session or a cookie, and it is reused by the
 * frontend rendering decision, the backend workflow and the mode-switch guard.
 */
final class ContentTranslationModeResolver
{
    public function __construct(
        private readonly SiteLanguageRegistryInterface $siteLanguages,
        private readonly CanonicalUrlPolicy $urlPolicy,
    ) {
    }

    public function getModeForPageLanguage(PageModel $page, string $language): ContentTranslationMode
    {
        return $this->getModeForRoot($this->rootPageId($page), $language);
    }

    /**
     * The default language always renders the source structure, which is
     * expressed as "connected".
     */
    public function getModeForRoot(int $rootPageId, string $language): ContentTranslationMode
    {
        if ($rootPageId <= 0 || '' === trim($language)) {
            return ContentTranslationMode::Connected;
        }

        try {
            if ($this->isDefaultLanguage($rootPageId, $language)) {
                return ContentTranslationMode::Connected;
            }

            return $this->siteLanguages->contentMode($rootPageId, $language);
        } catch (\Throwable) {
            // A missing or broken configuration never enables free mode.
            return ContentTranslationMode::Connected;
        }
    }

    public function isDefaultLanguage(int $rootPageId, string $language): bool
    {
        return $this->urlPolicy->languagesEqual($language, $this->siteLanguages->defaultLanguage($rootPageId));
    }

    public function rootPageId(PageModel $page): int
    {
        try {
            return (int) ('root' === $page->type ? $page->id : $page->rootId);
        } catch (\Throwable) {
            return 0;
        }
    }
}
