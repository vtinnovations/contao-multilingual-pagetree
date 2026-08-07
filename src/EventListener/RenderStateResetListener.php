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

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ModelSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentModeContext;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ModelTranslationRecordLocator;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ScopedModelOverlay;

/**
 * Releases all per-request state at the end of the main request.
 *
 * Every overlay is normally released by the hook that follows the render
 * operation. This listener is the safety net for render operations that were
 * aborted by an exception, and it keeps long running workers from carrying
 * translated model state, cached translation records or cached site language
 * configuration into the next request.
 *
 * Sub requests are ignored on purpose: a fragment rendered in a sub request runs
 * inside the render operation of its parent, whose overlay must stay active.
 */
#[AsEventListener(event: KernelEvents::FINISH_REQUEST, priority: -255)]
class RenderStateResetListener
{
    public function __construct(
        private readonly ScopedModelOverlay $scopedOverlay,
        private readonly ModelTranslationRecordLocator $translationLocator,
        private readonly ModelSiteLanguageRegistry $siteLanguages,
        private readonly ContentModeContext $contentMode,
    ) {
    }

    public function __invoke(FinishRequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->scopedOverlay->restoreAll();
        $this->translationLocator->reset();
        $this->siteLanguages->reset();
        $this->contentMode->reset();
    }
}
