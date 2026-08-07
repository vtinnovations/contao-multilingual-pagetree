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

namespace Vtinnovations\ContaoMultilingualPagetree\Backend;

use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\UsageSignal;
use Vtinnovations\ContaoMultilingualPagetree\Helper\Clock;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\InstallationIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;

/**
 * Claims the one module-entry event of the current authenticated backend
 * session.
 *
 * The event belongs to entering this bundle's own backend section, not to
 * evaluating an entitlement, so it is triggered from that render lifecycle and
 * never from a kernel listener, a frontend request, a console command, a worker
 * or a service constructor.
 *
 * Deduplication uses Contao's own server-side backend session, per website root:
 * entitlement is scoped to a root and each root carries its own key, so entering
 * the section of a second root in the same session is a second entry. A marker
 * is claimed *before* delivery is scheduled, so parallel tabs, a reload, an AJAX
 * request or a second node cannot produce a second event for the same root, and
 * a delivery that times out is not retried within that session. The marker holds
 * a bare boolean under a name carrying only the root id: no key, no host, no
 * session identifier and no payload. It disappears with the session, so a new
 * login may emit once again.
 *
 * The key comes only from the locally verified record - {@see
 * PackageStoreInterface::load()} returns nothing unless every signature and the
 * exact-byte digest passed. It is deliberately still sent when the record is
 * authentic but the entitlement is withheld for host, period or status reasons,
 * because that is exactly the case the issuer needs to see. When no authentic
 * key exists nothing is claimed and nothing is sent, so a later session can
 * still emit the event.
 *
 * The host is the current trusted installation host, never the host stored in
 * the record, so a record copied to another domain is visible as such.
 */
final class ModuleEntryNotice
{
    /**
     * Neutral session key prefix. It says nothing about what it guards and
     * holds no payload of its own.
     */
    private const MARKER = 'contao_multilingual_pagetree.entry';

    public function __construct(
        private readonly UsageSignal $signal,
        private readonly PackageStoreInterface $store,
        private readonly InstallationIdentity $identity,
        private readonly Clock $clock,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?RootScope $scope = null,
    ) {
    }

    /**
     * Called when this bundle's backend section is rendered for an authenticated
     * user. Every failure is silent: this must never affect what is rendered.
     */
    public function noteEntry(): void
    {
        try {
            $this->claim();
        } catch (\Throwable) {
            // A signal is never allowed to break a backend screen.
        }
    }

    private function claim(): void
    {
        $host = $this->identity->current();

        if (null === $host) {
            return;
        }

        $session = $this->session();

        if (null === $session) {
            // Without a server-side session there is no way to guarantee "once
            // per session", so the event is skipped rather than risked twice.
            return;
        }

        $marker = $this->marker();

        if (true === $session->get($marker)) {
            return;
        }

        $package = $this->store->load($this->clock->now());
        $key = $package?->document->subscriptionKey;

        // No authentic record means no authentic key: nothing is sent and,
        // importantly, nothing is marked, so a later session can still emit.
        if (!is_string($key) || '' === $key) {
            return;
        }

        // Claimed before delivery is even scheduled. PHP serialises requests of
        // one session, so a parallel tab reaching this line finds the marker set.
        $session->set($marker, true);

        $this->signal->queueModuleEntry($host, $key);
    }

    /**
     * The session key this entry claims.
     *
     * Entitlement here is per website root, and each root carries its own key,
     * so the claim is per root as well: an administrator who opens the section
     * of two roots in one session is entering two separately licensed sections,
     * and the issuer needs to see both. A single session-wide marker would
     * silently drop the second.
     *
     * Only the root id goes into the name - never the key, the host, the session
     * identifier or anything derived from them.
     */
    private function marker(): string
    {
        $rootId = $this->scope?->rootId() ?? 0;

        return $rootId > 0 ? self::MARKER.'.'.$rootId : self::MARKER;
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack?->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        return $session->isStarted() ? $session : null;
    }
}
