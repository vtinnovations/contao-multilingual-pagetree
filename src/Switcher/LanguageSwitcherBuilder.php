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

namespace Vtinnovations\ContaoMultilingualPagetree\Switcher;

use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\HreflangCodeFormatter;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;

/**
 * Turns the language configuration of the current root site into switcher
 * entries whose state reflects the actual availability of the complete current
 * resource.
 *
 * The state is derived from the URL-driven language and the central
 * availability resolver only - never from a browser header, a session or a
 * cookie.
 */
final class LanguageSwitcherBuilder
{
    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly ResourceAvailabilityResolver $resourceResolver,
        private readonly HreflangCodeFormatter $codeFormatter,
        private readonly CanonicalUrlPolicy $urlPolicy,
    ) {
    }

    /**
     * @return list<SwitcherEntry>
     */
    public function build(
        Request $request,
        PageModel $page,
        UnavailableLanguageDisplay $display,
        bool $hideActive = false,
    ): array {
        $rootPageId = 'root' === $page->type ? (int) $page->id : (int) $page->rootId;
        $configuration = [];

        foreach ($this->languageHelper->getAvailableLanguages($rootPageId) as $language) {
            $code = (string) ($language['language'] ?? '');

            if ('' !== $code) {
                $configuration[$code] = $language;
            }
        }

        $entries = [];

        foreach ($this->resourceResolver->resolveAll($request, $page) as $result) {
            $language = $configuration[$result->targetLanguage] ?? null;

            if (null === $language) {
                continue;
            }

            $entry = new SwitcherEntry(
                $result->targetLanguage,
                (string) ($language['label'] ?? $result->targetLanguage),
                (string) ($language['flag'] ?? ''),
                $this->codeFormatter->format($result->targetLanguage) ?? '',
                $result->status,
                $result->isLinkable() ? $result->url : null,
                $result->usesPageFallback,
                $result->previewOnly,
                $result->reason->value,
            );

            if ($hideActive && $entry->isActive()) {
                continue;
            }

            if ($entry->isUnavailable() && !$display->showsUnavailable()) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildTemplateData(
        Request $request,
        PageModel $page,
        UnavailableLanguageDisplay $display,
        bool $hideActive = false,
    ): array {
        return array_map(
            static fn (SwitcherEntry $entry): array => $entry->toArray(),
            $this->build($request, $page, $display, $hideActive),
        );
    }

    public function isActiveLanguage(string $language): bool
    {
        return $this->urlPolicy->languagesEqual($language, $this->languageHelper->getActiveLanguage());
    }
}
