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

use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\UsageSignal;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\InstallationIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;

/**
 * Emits the usage signal after the response has been sent.
 *
 * `kernel.terminate` runs once per main request, after the visitor already has
 * the page, so the call can never delay rendering or change what is shown. Sub
 * requests never reach this listener, and the update endpoint is skipped so a
 * server-to-server call does not trigger a second server-to-server call.
 *
 * Failure is silent by design: the signal has no influence on the entitlement.
 */
final class UsageSignalListener
{
    public function __construct(
        private readonly UsageSignal $signal,
        private readonly InstallationIdentity $identity,
        private readonly ?RootScope $rootContext = null,
        private readonly ?RootDomainRegistry $rootDomains = null,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        // A module-entry event claimed during this request is delivered here,
        // after the response, whatever else this listener decides. It was
        // claimed in the session before it was queued, so it cannot repeat.
        $this->signal->deliverQueuedModuleEntry();

        if (ProductProfile::ENDPOINT_PATH === $event->getRequest()->getPathInfo()) {
            return;
        }

        // Signalling is meaningful only after a concrete frontend root has
        // selected its exact configured domain.
        if (null !== $this->rootContext && null === $this->rootContext->domain() && null !== $this->rootDomains) {
            $page = $event->getRequest()->attributes->get('_contao_page') ?? $event->getRequest()->attributes->get('pageModel');
            $rootId = is_object($page) ? (int) (($page->type ?? '') === 'root' ? ($page->id ?? 0) : ($page->rootId ?? 0)) : 0;
            $domain = $this->rootDomains->domain($rootId);
            if ($rootId > 0 && null !== $domain) {
                $this->rootContext->select($rootId, $domain);
            }
        }
        $host = null !== $this->rootContext ? $this->rootContext->domain() : $this->identity->fromRequest();

        if (null === $host) {
            return;
        }

        $this->signal->send($host);
    }
}
