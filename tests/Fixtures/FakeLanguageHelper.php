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

use Contao\PageModel;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PublicationChecker;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;

/**
 * Language helper without request/framework dependencies.
 *
 * The publication logic of the real helper is intentionally inherited so the
 * tests exercise the production implementation.
 */
class FakeLanguageHelper extends LanguageHelper
{
    public function __construct(
        private string $activeLanguage = 'de',
        private string $defaultLanguage = 'en',
        private bool $frontendRequest = true,
        private ?PageModel $currentPage = null,
    ) {
        // The parent dependencies are deliberately not initialised: only the
        // request independent methods below are used by the render listeners.
    }

    public function isFrontendRequest(): bool
    {
        return $this->frontendRequest;
    }

    public function getActiveLanguage(): string
    {
        return $this->activeLanguage;
    }

    public function getCurrentPageModel(): ?PageModel
    {
        return $this->currentPage;
    }

    public function getRootPageId(): int
    {
        return null === $this->currentPage ? 0 : (int) $this->currentPage->rootId;
    }

    public function isDefaultLanguage(?string $language = null, ?int $rootPageId = null): bool
    {
        return ($language ?? $this->activeLanguage) === $this->defaultLanguage;
    }

    /**
     * The real publication logic, without the constructor dependencies of the
     * production helper.
     */
    public function isPublished(object|array|null $record): bool
    {
        return (new PublicationChecker())->isPublished($record);
    }
}
