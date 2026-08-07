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

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;

/** Selects the root licence before protected frontend services are evaluated. */
final class RootScopeListener
{
    public function __construct(
        private readonly RootScope $context,
        private readonly RootDomainRegistry $roots,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $page = $request->attributes->get('_contao_page') ?? $request->attributes->get('pageModel');
        $rootId = is_object($page) ? (int) (($page->type ?? '') === 'root' ? ($page->id ?? 0) : ($page->rootId ?? 0)) : 0;
        if ($rootId <= 0) {
            $candidate = $request->attributes->get('rootPageId');
            $rootId = is_numeric($candidate) ? (int) $candidate : 0;
        }
        $domain = $this->roots->domain($rootId);
        if ($rootId > 0 && null !== $domain) {
            $this->context->select($rootId, $domain);
        }
    }
}
