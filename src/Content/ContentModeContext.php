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

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;

/**
 * Request-scoped answer to "does this article or content record belong to the
 * tree that is currently being rendered?".
 *
 * The decision is taken once per request from the URL-driven active language and
 * the configured content mode of the current root site, and is then applied to
 * every record by the existing pre-render hooks. Rendering itself stays entirely
 * with Contao: nothing is instantiated, regenerated or concatenated here.
 */
final class ContentModeContext implements ResetInterface
{
    private ?string $language = null;
    private ?bool $isDefaultLanguage = null;
    private ?ContentTranslationMode $mode = null;
    private int $rootPageId = 0;

    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly ContentTranslationModeResolver $modeResolver,
        private readonly ContentVisibilityPolicy $visibility,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed>|object $record
     */
    public function isRenderable(array|object $record): bool
    {
        try {
            $this->initialize();

            return $this->visibility->isRenderable(
                ContentOwnership::fromRecord($record),
                (string) $this->language,
                (bool) $this->isDefaultLanguage,
                $this->mode ?? ContentTranslationMode::Connected,
                $this->rootPageId,
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('Contao Multilingual Pagetree: could not resolve the content mode: '.$exception->getMessage());

            // A broken configuration renders the source structure.
            return ContentOwnership::fromRecord($record)->isSource();
        }
    }

    /**
     * @param array<string, mixed>|object $record
     */
    public function usesConnectedOverlay(array|object $record): bool
    {
        try {
            $this->initialize();

            return $this->visibility->usesConnectedOverlay(
                ContentOwnership::fromRecord($record),
                (bool) $this->isDefaultLanguage,
                $this->mode ?? ContentTranslationMode::Connected,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function mode(): ContentTranslationMode
    {
        $this->initialize();

        return $this->mode ?? ContentTranslationMode::Connected;
    }

    public function reset(): void
    {
        $this->language = null;
        $this->isDefaultLanguage = null;
        $this->mode = null;
        $this->rootPageId = 0;
    }

    private function initialize(): void
    {
        if (null !== $this->mode) {
            return;
        }

        $this->language = $this->languageHelper->getActiveLanguage();
        $this->rootPageId = $this->languageHelper->getRootPageId();
        $this->isDefaultLanguage = $this->languageHelper->isDefaultLanguage($this->language, $this->rootPageId);
        $this->mode = $this->isDefaultLanguage
            ? ContentTranslationMode::Connected
            : $this->modeResolver->getModeForRoot($this->rootPageId, $this->language);
    }
}
