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

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentFallbackMode;
use Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Language\LanguageFlagMapper;

/**
 * Reads the per-root language configuration from tl_inline_language.
 *
 * The configuration is memoised per root site for the duration of one request
 * and released afterwards, so routing does not re-query the same site for every
 * page and long running workers never serve stale configuration.
 */
class ModelSiteLanguageRegistry implements SiteLanguageRegistryInterface, ResetInterface
{
    protected const DEFAULT_LANGUAGE_FALLBACK = 'de';

    /** @var array<int, list<SiteLanguage>> */
    private array $languages = [];

    /** @var array<int, string> */
    private array $defaultLanguages = [];

    public function __construct(
        private readonly ?ContaoFramework $framework,
        private readonly CanonicalUrlPolicy $urlPolicy,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?LanguageFlagMapper $flagMapper = null,
    ) {
    }

    public function languages(int $rootPageId): array
    {
        if ($rootPageId <= 0) {
            return [];
        }

        if (isset($this->languages[$rootPageId])) {
            return $this->languages[$rootPageId];
        }

        $languages = [];
        $defaultLanguage = $this->defaultLanguage($rootPageId);

        // The native root page is the canonical source-language record. It has
        // no tl_inline_language row, so derive only its presentation flag from
        // the same mapping used by the backend selector.
        $languages[] = new SiteLanguage(
            $defaultLanguage,
            strtoupper($defaultLanguage),
            ($this->flagMapper ?? new LanguageFlagMapper())->defaultFlag($defaultLanguage),
            true,
            PageAvailabilityMode::Fallback,
            ContentTranslationMode::Connected,
            0,
            ContentFallbackMode::Fallback,
        );

        foreach ($this->fetchLanguageRecords($rootPageId) as $record) {
            $siteLanguage = $this->createSiteLanguage($record);

            if (null !== $siteLanguage && !$this->urlPolicy->languagesEqual($siteLanguage->language, $defaultLanguage)) {
                $languages[] = $siteLanguage;
            }
        }

        return $this->languages[$rootPageId] = $languages;
    }

    public function defaultLanguage(int $rootPageId): string
    {
        if ($rootPageId <= 0) {
            return static::DEFAULT_LANGUAGE_FALLBACK;
        }

        if (isset($this->defaultLanguages[$rootPageId])) {
            return $this->defaultLanguages[$rootPageId];
        }

        $language = $this->fetchDefaultLanguage($rootPageId);

        if (null === $language || null === $this->urlPolicy->normalizeLanguage($language)) {
            $language = static::DEFAULT_LANGUAGE_FALLBACK;
        }

        return $this->defaultLanguages[$rootPageId] = $language;
    }

    public function isEnabled(int $rootPageId, string $language): bool
    {
        foreach ($this->languages($rootPageId) as $configured) {
            if ($this->urlPolicy->languagesEqual($language, $configured->language)) {
                return true;
            }
        }

        return $this->urlPolicy->languagesEqual($language, $this->defaultLanguage($rootPageId));
    }

    public function mode(int $rootPageId, string $language): PageAvailabilityMode
    {
        // The default language always uses the source page tree.
        if ($this->urlPolicy->languagesEqual($language, $this->defaultLanguage($rootPageId))) {
            return PageAvailabilityMode::Fallback;
        }

        foreach ($this->languages($rootPageId) as $configured) {
            if ($this->urlPolicy->languagesEqual($language, $configured->language)) {
                return $configured->mode;
            }
        }

        return PageAvailabilityMode::Fallback;
    }

    public function contentMode(int $rootPageId, string $language): ContentTranslationMode
    {
        // The default language always renders the source content structure.
        if ($this->urlPolicy->languagesEqual($language, $this->defaultLanguage($rootPageId))) {
            return ContentTranslationMode::Connected;
        }

        foreach ($this->languages($rootPageId) as $configured) {
            if ($this->urlPolicy->languagesEqual($language, $configured->language)) {
                return $configured->contentMode;
            }
        }

        return ContentTranslationMode::Connected;
    }

    public function contentFallbackMode(int $rootPageId, string $language): ContentFallbackMode
    {
        if ($this->urlPolicy->languagesEqual($language, $this->defaultLanguage($rootPageId))) {
            return ContentFallbackMode::Fallback;
        }

        foreach ($this->languages($rootPageId) as $configured) {
            if ($this->urlPolicy->languagesEqual($language, $configured->language)) {
                return $configured->contentFallbackMode;
            }
        }

        return ContentFallbackMode::Fallback;
    }

    public function reset(): void
    {
        $this->languages = [];
        $this->defaultLanguages = [];
    }

    /**
     * @return iterable<object>
     */
    protected function fetchLanguageRecords(int $rootPageId): iterable
    {
        if (null === $this->framework) {
            return [];
        }

        try {
            $this->framework->initialize();
            $models = $this->framework->getAdapter(MultilingualPagetreeModel::class)->findPublishedByPid($rootPageId);

            return null === $models ? [] : $models;
        } catch (\Throwable $exception) {
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: could not read the language configuration of root page %d: %s', $rootPageId, $exception->getMessage()),
            );

            return [];
        }
    }

    protected function fetchDefaultLanguage(int $rootPageId): ?string
    {
        if (null === $this->framework) {
            return null;
        }

        try {
            $this->framework->initialize();
            $rootPage = $this->framework->getAdapter(PageModel::class)->findByPk($rootPageId);

            if (null !== $rootPage && null !== $this->urlPolicy->normalizeLanguage((string) $rootPage->language)) {
                return (string) $rootPage->language;
            }

            // Legacy compatibility only: old installations may still carry a
            // fallback row. It is consulted solely when the native root
            // language is unavailable and is never returned as an additional
            // target language.
            $model = $this->framework->getAdapter(MultilingualPagetreeModel::class)->findFallbackByPid($rootPageId);

            if (null !== $model && null !== $this->urlPolicy->normalizeLanguage((string) $model->language)) {
                return (string) $model->language;
            }
        } catch (\Throwable $exception) {
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: could not read the default language of root page %d: %s', $rootPageId, $exception->getMessage()),
            );
        }

        return null;
    }

    private function createSiteLanguage(object $record): ?SiteLanguage
    {
        $language = (string) ($record->language ?? '');

        if (null === $this->urlPolicy->normalizeLanguage($language)) {
            return null;
        }

        $isDefault = (bool) ($record->fallback ?? false);

        return new SiteLanguage(
            $language,
            (string) ($record->label ?? ''),
            (string) ($record->flag ?? ''),
            $isDefault,
            // The default language never carries a meaningful mode.
            $isDefault
                ? PageAvailabilityMode::Fallback
                : PageAvailabilityMode::fromValue($record->pageAvailabilityMode ?? null),
            $isDefault
                ? ContentTranslationMode::Connected
                : ContentTranslationMode::fromValue($record->contentTranslationMode ?? null),
            (int) ($record->id ?? 0),
            $isDefault
                ? ContentFallbackMode::Fallback
                : ContentFallbackMode::fromValue($record->contentFallbackMode ?? null),
        );
    }
}
