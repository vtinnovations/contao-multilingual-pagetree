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

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity;

use Contao\CoreBundle\Framework\ContaoFramework;
use Psr\Log\LoggerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ModelSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentModeContext;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ModelTranslationRecordLocator;

/**
 * Invalidates exactly the caches an integrity change can affect.
 *
 * Contao's own HTTP cache is tagged per page, so the invalidator uses the
 * standard tag invalidator when it is available and always resets the bundle's
 * request-scoped registries. Nothing installation-wide is flushed here.
 */
final class TagBasedCacheInvalidator implements IntegrityCacheInvalidatorInterface
{
    public function __construct(
        private readonly ModelSiteLanguageRegistry $siteLanguages,
        private readonly ModelTranslationRecordLocator $translationLocator,
        private readonly ContentModeContext $contentMode,
        private readonly ?ContaoFramework $framework = null,
        private readonly ?object $tagInvalidator = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function invalidateRoot(int $rootPageId): void
    {
        $this->resetRegistries();

        if ($rootPageId <= 0) {
            return;
        }

        // Contao tags page responses with "contao.db.tl_page.<id>"; invalidating
        // the root tag only affects this site.
        $this->invalidateTags(['contao.db.tl_page.'.$rootPageId]);

        $this->logger?->info(sprintf(
            'Contao Multilingual Pagetree: invalidated integrity caches for root page %d.',
            $rootPageId,
        ));
    }

    public function invalidatePage(int $pageId): void
    {
        $this->resetRegistries();

        if ($pageId > 0) {
            $this->invalidateTags(['contao.db.tl_page.'.$pageId]);
        }
    }

    /**
     * @param list<string> $tags
     */
    private function invalidateTags(array $tags): void
    {
        if (null === $this->tagInvalidator || !method_exists($this->tagInvalidator, 'invalidateTags')) {
            return;
        }

        try {
            $this->tagInvalidator->invalidateTags($tags);
        } catch (\Throwable $exception) {
            // A failed cache invalidation is reported but never breaks a repair.
            $this->logger?->error('Contao Multilingual Pagetree: cache tag invalidation failed: '.$exception->getMessage());
        }
    }

    private function resetRegistries(): void
    {
        try {
            $this->framework?->initialize();
            $this->siteLanguages->reset();
            $this->translationLocator->reset();
            $this->contentMode->reset();
        } catch (\Throwable $exception) {
            $this->logger?->error('Contao Multilingual Pagetree: could not reset request caches: '.$exception->getMessage());
        }
    }
}
