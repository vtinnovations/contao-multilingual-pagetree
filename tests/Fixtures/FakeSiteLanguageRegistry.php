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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode;
use Vtinnovations\ContaoMultilingualPagetree\Availability\SiteLanguage;
use Vtinnovations\ContaoMultilingualPagetree\Availability\SiteLanguageRegistryInterface;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentFallbackMode;

/**
 * In-memory language configuration of one or more root sites.
 */
class FakeSiteLanguageRegistry implements SiteLanguageRegistryInterface
{
    /** @var array<int, list<SiteLanguage>> */
    private array $sites = [];

    /**
     * @param array<int, array<string, string>> $sites Root page id => [language => mode|'default']
     */
    /** @var array<int, array<string, ContentTranslationMode>> */
    private array $contentModes = [];

    public function __construct(array $sites = [])
    {
        foreach ($sites as $rootPageId => $languages) {
            foreach ($languages as $language => $mode) {
                $this->add($rootPageId, (string) $language, (string) $mode);
            }
        }
    }

    public function add(int $rootPageId, string $language, string $mode, string $contentMode = 'connected'): self
    {
        $isDefault = 'default' === $mode;
        $this->contentModes[$rootPageId][$language] = $isDefault
            ? ContentTranslationMode::Connected
            : ContentTranslationMode::fromValue($contentMode);

        $this->sites[$rootPageId][] = new SiteLanguage(
            $language,
            strtoupper($language),
            $language,
            $isDefault,
            $isDefault ? PageAvailabilityMode::Fallback : PageAvailabilityMode::fromValue($mode),
            $this->contentModes[$rootPageId][$language],
        );

        return $this;
    }

    public function languages(int $rootPageId): array
    {
        return $this->sites[$rootPageId] ?? [];
    }

    public function defaultLanguage(int $rootPageId): string
    {
        foreach ($this->languages($rootPageId) as $language) {
            if ($language->isDefault) {
                return $language->language;
            }
        }

        return 'en';
    }

    public function isEnabled(int $rootPageId, string $language): bool
    {
        foreach ($this->languages($rootPageId) as $configured) {
            if ($configured->language === $language) {
                return true;
            }
        }

        return false;
    }

    public function mode(int $rootPageId, string $language): PageAvailabilityMode
    {
        foreach ($this->languages($rootPageId) as $configured) {
            if ($configured->language === $language) {
                return $configured->mode;
            }
        }

        return PageAvailabilityMode::Fallback;
    }

    public function contentMode(int $rootPageId, string $language): ContentTranslationMode
    {
        if ($language === $this->defaultLanguage($rootPageId)) {
            return ContentTranslationMode::Connected;
        }

        return $this->contentModes[$rootPageId][$language] ?? ContentTranslationMode::Connected;
    }

    public function contentFallbackMode(int $rootPageId, string $language): ContentFallbackMode
    {
        foreach ($this->languages($rootPageId) as $configured) {
            if ($configured->language === $language) {
                return $configured->contentFallbackMode;
            }
        }

        return ContentFallbackMode::Fallback;
    }
}
