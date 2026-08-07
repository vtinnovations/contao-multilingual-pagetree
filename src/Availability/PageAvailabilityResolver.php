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

namespace Vtinnovations\ContaoMultilingualPagetree\Availability;

use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordLocatorInterface;

/**
 * The single authority for "is this page available in this language, and what
 * does it render?".
 *
 * Routing, request handling, page metadata, the language switcher and the
 * detail-page URL resolver all ask this service instead of repeating the
 * decision. The answer depends only on the root site, the target language, the
 * source page, the translation record, the configured mode, publication state,
 * the current time and Contao's preview context - never on the browser,
 * a session or a cookie.
 */
final class PageAvailabilityResolver
{
    private const TRANSLATION_TABLE = 'tl_page_translation';

    /**
     * Runtime property in which the untouched source alias is preserved before
     * a page overlay replaces the alias of the current page model. Without it a
     * rendered translated page would report its translated alias as the source
     * alias and produce wrong default-language and fallback URLs.
     */
    public const SOURCE_ALIAS_PROPERTY = 'multilingualPagetreeSourceAlias';

    public function __construct(
        private readonly SiteLanguageRegistryInterface $languages,
        private readonly TranslationRecordLocatorInterface $translationLocator,
        private readonly TranslationOverlayResolver $overlayResolver,
        private readonly CanonicalUrlPolicy $urlPolicy,
        private readonly PublicationChecker $publicationChecker,
        private readonly ?TokenChecker $tokenChecker = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?LanguageUrlResolver $urlResolver = null,
    ) {
    }

    /**
     * @param bool $ignorePreview Evaluate public availability even when an
     *                            authorised preview context is active
     */
    public function resolve(PageModel $sourcePage, string $targetLanguage, ?Request $request = null, bool $ignorePreview = false): PageAvailabilityResult
    {
        $rootPageId = $this->rootPageId($sourcePage);
        $defaultLanguage = $rootPageId > 0 ? $this->languages->defaultLanguage($rootPageId) : '';
        $mode = PageAvailabilityMode::Fallback;
        $sourceAlias = '';
        $isRootPage = false;

        try {
            $sourceAlias = $this->sourceAlias($sourcePage);
            $isRootPage = $this->isRootPage($sourcePage, $sourceAlias);

            if ($rootPageId <= 0) {
                return PageAvailabilityResult::unavailable(
                    $sourcePage,
                    null,
                    $targetLanguage,
                    $defaultLanguage,
                    $mode,
                    PageAvailabilityReason::SourcePageUnavailable,
                    $sourceAlias,
                    $isRootPage,
                );
            }

            $isDefaultLanguage = $this->urlPolicy->languagesEqual($targetLanguage, $defaultLanguage);
            $mode = $isDefaultLanguage
                ? PageAvailabilityMode::Fallback
                : $this->languages->mode($rootPageId, $targetLanguage);

            // A language mode never makes an unavailable source page visible.
            if (!$this->publicationChecker->isPublished($sourcePage, $this->isPreviewMode($ignorePreview))) {
                return PageAvailabilityResult::unavailable(
                    $sourcePage,
                    null,
                    $targetLanguage,
                    $defaultLanguage,
                    $mode,
                    PageAvailabilityReason::SourcePageUnavailable,
                    $sourceAlias,
                    $isRootPage,
                );
            }

            if ($isDefaultLanguage) {
                return PageAvailabilityResult::defaultLanguage(
                    $sourcePage,
                    $targetLanguage,
                    $defaultLanguage,
                    $sourceAlias,
                    $isRootPage,
                );
            }

            if (!$this->languages->isEnabled($rootPageId, $targetLanguage)) {
                return PageAvailabilityResult::unavailable(
                    $sourcePage,
                    null,
                    $targetLanguage,
                    $defaultLanguage,
                    $mode,
                    PageAvailabilityReason::LanguageNotConfigured,
                    $sourceAlias,
                    $isRootPage,
                );
            }

            [$translation, $reason] = $this->findTranslation($sourcePage, $targetLanguage, $ignorePreview);

            if (null !== $translation && PageAvailabilityReason::Available === $reason) {
                $alias = $this->translatedAlias($sourcePage, $translation);

                if ('' !== $alias || $isRootPage) {
                    return PageAvailabilityResult::translated(
                        $sourcePage,
                        $translation,
                        $targetLanguage,
                        $defaultLanguage,
                        $mode,
                        '' !== $alias ? $alias : $sourceAlias,
                        $sourceAlias,
                        $isRootPage,
                    );
                }

                $reason = PageAvailabilityReason::InvalidAlias;
            }

            if ($mode->isStrict()) {
                return PageAvailabilityResult::unavailable(
                    $sourcePage,
                    $translation,
                    $targetLanguage,
                    $defaultLanguage,
                    $mode,
                    $reason,
                    $sourceAlias,
                    $isRootPage,
                );
            }

            // Fallback mode: the source page provides content and alias.
            if ('' === $sourceAlias && !$isRootPage) {
                return PageAvailabilityResult::unavailable(
                    $sourcePage,
                    $translation,
                    $targetLanguage,
                    $defaultLanguage,
                    $mode,
                    PageAvailabilityReason::InvalidAlias,
                    $sourceAlias,
                    $isRootPage,
                );
            }

            return PageAvailabilityResult::fallback(
                $sourcePage,
                $translation,
                $targetLanguage,
                $defaultLanguage,
                $reason,
                $sourceAlias,
                $isRootPage,
            );
        } catch (\Throwable $exception) {
            $this->logger?->error(
                sprintf(
                    'Contao Multilingual Pagetree: could not resolve the availability of page %s in "%s": %s',
                    (string) ($this->read($sourcePage, 'id') ?? '?'),
                    $targetLanguage,
                    $exception->getMessage(),
                ),
            );

            // Safe defaults: strict never guesses, fallback keeps the source page.
            if ($mode->isStrict() || ('' === $sourceAlias && !$isRootPage)) {
                return PageAvailabilityResult::unavailable(
                    $sourcePage,
                    null,
                    $targetLanguage,
                    $defaultLanguage,
                    $mode,
                    PageAvailabilityReason::ResolutionFailed,
                    $sourceAlias,
                    $isRootPage,
                );
            }

            return PageAvailabilityResult::fallback(
                $sourcePage,
                null,
                $targetLanguage,
                $defaultLanguage,
                PageAvailabilityReason::ResolutionFailed,
                $sourceAlias,
                $isRootPage,
            );
        }
    }

    /**
     * The canonical path of an available result: the unprefixed source alias for
     * the default language, the prefixed translated alias for an available
     * translation and the prefixed source alias for a fallback page.
     */
    public function canonicalPath(PageAvailabilityResult $result, ?string $urlSuffix = null): ?string
    {
        if ($result->isUnavailable() || null === $result->effectiveAlias) {
            return null;
        }

        $suffix = $result->isRootPage
            ? ''
            : ($urlSuffix ?? (string) ($this->read($result->sourcePage, 'urlSuffix') ?? ''));

        return $this->urlPolicy->buildPagePath(
            $result->defaultLanguage,
            $result->targetLanguage,
            $result->sourceAlias,
            $result->effectiveAlias,
            $suffix,
            $result->isRootPage,
            $this->entryPoint($result),
        );
    }

    /**
     * The effective entry point of the target language, from the one central
     * language URL mapping. Null keeps the previous prefix strategy, which is
     * exactly what an installation without a configured mapping gets.
     */
    private function entryPoint(PageAvailabilityResult $result): ?string
    {
        if (null === $this->urlResolver) {
            return null;
        }

        $rootPageId = $this->rootPageId($result->sourcePage);

        if ($rootPageId <= 0) {
            return null;
        }

        $mapping = $this->urlResolver->forLanguage($rootPageId, $result->targetLanguage);

        if (null !== $mapping) {
            return $mapping->effectiveEntryPoint;
        }

        // No mapping at all: the caller falls back to the previous strategy,
        // which derives the language code. That is correct for an installation
        // without any configured mapping, but it is also the one way a language
        // *with* a configured domain could still be given a code it does not
        // use - so it is recorded rather than passing silently.
        $this->logger?->debug('Contao Multilingual Pagetree: no language URL mapping; falling back to the language-code path.', [
            'rootId' => $rootPageId,
            'language' => $result->targetLanguage,
        ]);

        return null;
    }

    /**
     * The prefixed source-alias path of a target language, i.e. the path a
     * fallback page uses. It becomes an obsolete URL as soon as a translated
     * alias is available.
     */
    public function fallbackPath(PageAvailabilityResult $result, ?string $urlSuffix = null): ?string
    {
        if ($result->isRootPage || '' === $result->sourceAlias) {
            return null;
        }

        $suffix = $urlSuffix ?? (string) ($this->read($result->sourcePage, 'urlSuffix') ?? '');

        return $this->urlPolicy->buildPagePath(
            $result->defaultLanguage,
            $result->targetLanguage,
            $result->sourceAlias,
            $result->sourceAlias,
            $suffix,
            false,
            $this->entryPoint($result),
        );
    }

    /**
     * @return array{0: object|null, 1: PageAvailabilityReason}
     */
    private function findTranslation(PageModel $sourcePage, string $targetLanguage, bool $ignorePreview = false): array
    {
        $pageId = (int) ($this->read($sourcePage, 'id') ?? 0);

        if ($pageId <= 0) {
            return [null, PageAvailabilityReason::OrphanedRelation];
        }

        $translation = $this->translationLocator->find(self::TRANSLATION_TABLE, $pageId, $targetLanguage);

        if (null === $translation) {
            return [null, PageAvailabilityReason::NoTranslation];
        }

        // The record must really belong to this source page and language; a
        // translation of another root site can never satisfy availability
        // because it is looked up by this page's own id.
        if ((int) ($this->read($translation, 'pid') ?? 0) !== $pageId) {
            return [$translation, PageAvailabilityReason::OrphanedRelation];
        }

        $recordLanguage = (string) ($this->read($translation, 'language') ?? '');

        if (!$this->urlPolicy->languagesEqual($recordLanguage, $targetLanguage)) {
            return [$translation, PageAvailabilityReason::WrongLanguage];
        }

        $reason = $this->publicationChecker->publicationReason($translation, $this->isPreviewMode($ignorePreview));

        return [$translation, $reason];
    }

    private function translatedAlias(PageModel $sourcePage, object $translation): string
    {
        // Field states from point 2 decide whether the alias is inherited,
        // custom or deliberately empty.
        $alias = $this->overlayResolver->resolveField($sourcePage, $translation, 'alias', self::TRANSLATION_TABLE);

        return is_scalar($alias) ? trim((string) $alias) : '';
    }

    private function rootPageId(PageModel $page): int
    {
        try {
            $type = (string) ($this->read($page, 'type') ?? '');

            return (int) ('root' === $type ? $this->read($page, 'id') : $this->read($page, 'rootId'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The alias of the source record, even when the current page model already
     * carries a translated alias overlay.
     */
    private function sourceAlias(PageModel $page): string
    {
        $preserved = $this->read($page, self::SOURCE_ALIAS_PROPERTY);

        if (is_string($preserved) && '' !== $preserved) {
            return $preserved;
        }

        return (string) ($this->read($page, 'alias') ?? '');
    }

    private function isRootPage(PageModel $page, string $alias): bool
    {
        return 'root' === (string) ($this->read($page, 'type') ?? '') || 'index' === $alias || '/' === $alias;
    }

    private function isPreviewMode(bool $ignorePreview = false): bool
    {
        if ($ignorePreview) {
            return false;
        }

        try {
            return null !== $this->tokenChecker && $this->tokenChecker->isPreviewMode();
        } catch (\Throwable) {
            return false;
        }
    }

    private function read(object $record, string $field): mixed
    {
        if (method_exists($record, 'row')) {
            $row = $record->row();

            if (is_array($row) && array_key_exists($field, $row)) {
                return $row[$field];
            }
        }

        return $record->$field ?? null;
    }
}
