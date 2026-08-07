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

namespace Vtinnovations\ContaoMultilingualPagetree\Controller\Backend;

use Contao\Message;
use Contao\System;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\RegistrationClient;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Security\RootPagePermission;
use Vtinnovations\ContaoMultilingualPagetree\Backend\PageRootPalette;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Security\BackendActionGuard;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;

/**
 * The four explicit licence operations, plus removal, as dedicated root-scoped
 * POST actions.
 *
 * Each operation has its own route, its own precondition and its own audit
 * record, so an operator can see exactly which one was run. They deliberately
 * share the single verified exchange underneath rather than growing a second
 * protocol path:
 *
 *  - **activate** performs the first activation of a root that has nothing
 *    stored yet;
 *  - **replace** exchanges the key of a root that already has stored state;
 *  - **refresh** re-runs the remote exchange for stored state and applies a
 *    newer package;
 *  - **verify** re-runs the *local* chain only - signatures, exact-byte digest,
 *    exact host, period - and reports what it found without contacting anything.
 *    It is the operation to reach for when the question is "is what is stored
 *    here still intact and still mine?".
 *
 * Every action re-authorises server side through {@see prepare()}: the posted
 * domain, the posted root and the storage path are never trusted, and a request
 * that fails any part of the guard changes nothing.
 */
final class LicenseController
{
    public function __construct(
        private readonly RegistrationClient $client,
        private readonly CapabilityPolicy $policy,
        private readonly PackageStoreInterface $store,
        private readonly BackendActionGuard $guard,
        private readonly RootPagePermission $permission,
        private readonly RootDomainRegistry $domains,
        private readonly RootScope $context,
        private readonly RouterInterface $router,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * First activation of a root that has nothing stored yet.
     *
     * A root that already holds state is sent to `replace` instead, so a stray
     * duplicate submission cannot silently overwrite a working licence with a
     * different key.
     */
    public function activate(Request $request, int $rootId): RedirectResponse
    {
        if (!$this->prepare($request, $rootId)) return $this->redirect($rootId);
        if ($this->stored()) return $this->finish($rootId, 'activate', 'already_activated', false);

        return $this->submitKey($request, $rootId, 'activate');
    }

    /** Exchanges the key of a root that already has stored state. */
    public function replace(Request $request, int $rootId): RedirectResponse
    {
        if (!$this->prepare($request, $rootId)) return $this->redirect($rootId);
        if (!$this->stored()) return $this->finish($rootId, 'replace', 'not_activated', false);

        return $this->submitKey($request, $rootId, 'replace');
    }

    /** Re-runs the remote exchange and applies a newer package. */
    public function refresh(Request $request, int $rootId): RedirectResponse
    {
        if (!$this->prepare($request, $rootId)) return $this->redirect($rootId);
        try {
            $outcome = $this->client->refresh();
            $this->policy->reset();

            return $this->finish($rootId, 'refresh', $outcome->status, $outcome->successful, $outcome->requestId);
        } catch (\Throwable) {
            return $this->finish($rootId, 'refresh', 'internal_error', false);
        }
    }

    /**
     * Re-runs the local verification chain only.
     *
     * Nothing is sent anywhere and nothing is written: the stored pair is read
     * back through exactly the chain that accepted it, and the entitlement is
     * re-evaluated against this root's exact domain. A licence that was edited,
     * truncated or copied from another host surfaces here.
     */
    public function verify(Request $request, int $rootId): RedirectResponse
    {
        if (!$this->prepare($request, $rootId)) return $this->redirect($rootId);
        if (!$this->stored()) return $this->finish($rootId, 'verify', 'not_activated', false);

        try {
            $this->policy->reset();
            $decision = $this->policy->decision();

            return $this->finish(
                $rootId,
                'verify',
                $decision->granted ? 'verified' : $decision->statusLabel(),
                $decision->granted,
            );
        } catch (\Throwable) {
            return $this->finish($rootId, 'verify', 'state_unusable', false);
        }
    }

    /**
     * The shared body of `activate` and `replace`: one submitted key, one fully
     * verified exchange, and no local change unless that exchange succeeded.
     */
    private function submitKey(Request $request, int $rootId, string $operation): RedirectResponse
    {
        $key = $request->request->get('licence_key');
        if (!is_string($key) || '' === trim($key)) return $this->finish($rootId, $operation, 'keyRequired', false);
        try {
            $outcome = $this->client->activate($key);
            $this->policy->reset();

            return $this->finish($rootId, $operation, $outcome->status, $outcome->successful, $outcome->requestId);
        } catch (\Throwable) {
            return $this->finish($rootId, $operation, 'internal_error', false);
        }
    }

    /** Whether this root already holds state, without trusting it. */
    private function stored(): bool
    {
        try {
            return $this->store->exists();
        } catch (\Throwable) {
            // An unreadable store is treated as "something is there": it must be
            // replaced deliberately, not activated over.
            return true;
        }
    }

    /**
     * Removes the stored state of this root. Multilingual data is never touched;
     * only the entitlement disappears.
     */
    public function remove(Request $request, int $rootId): RedirectResponse
    {
        if (!$this->prepare($request, $rootId)) return $this->redirect($rootId);
        // An explicit confirmation flag, so a replayed or hand-crafted POST
        // without it cannot discard a working licence.
        if ('1' !== (string) $request->request->get('confirm_remove')) return $this->redirect($rootId);

        try {
            $this->store->clear();
            $this->policy->reset();

            return $this->finish($rootId, 'remove', 'removed', true);
        } catch (\Throwable) {
            return $this->finish($rootId, 'remove', 'storage_failure', false);
        }
    }

    private function prepare(Request $request, int $rootId): bool
    {
        $domain = $this->domains->domain($rootId);
        $postedRoot = $request->request->get('root_id');
        $displayedDomain = $request->request->get('root_domain');
        if (!$this->guard->isWriteMethod($request) || !$this->guard->isTokenValid($request)
            || !$this->domains->isRoot($rootId) || !$this->permission->canManage($rootId)
            || !$this->permission->canEditField('tl_page', PageRootPalette::LICENCE_FIELD)
            || !is_numeric($postedRoot) || (int) $postedRoot !== $rootId
            || null === $domain || !is_string($displayedDomain) || !hash_equals($domain, $displayedDomain)
        ) {
            $this->message('permissionDenied', false);

            return false;
        }
        $this->context->select($rootId, $domain);

        return true;
    }

    private function finish(int $rootId, string $operation, string $key, bool $success, ?string $requestId = null): RedirectResponse
    {
        // One record per explicit operation, sharing the reference id the
        // exchange itself already used, so an operator can follow a single
        // operation end to end. Nothing about the key, the packet or any
        // signature is recorded here or anywhere else.
        $this->logger?->info('Contao Multilingual Pagetree licence operation.', [
            'operation' => $operation,
            'root_id' => $rootId,
            'domain' => $this->domains->domain($rootId),
            'result_code' => $key,
            'successful' => $success,
            'request_id' => $requestId,
        ]);

        $this->message($key, $success, $requestId);

        return $this->redirect($rootId);
    }

    private function message(string $key, bool $success, ?string $requestId = null): void
    {
        System::loadLanguageFile('default');
        $labels = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeRootLicence']['messages'] ?? [];
        if (str_starts_with($key, 'rejected:host_mismatch')) $key = 'wrong_domain';
        elseif (str_starts_with($key, 'rejected:')) $key = 'invalid_key';
        $message = (string) ($labels[$key] ?? $labels['verificationUnavailable'] ?? $key);
        if (null !== $requestId && 1 === preg_match('/^[0-9a-f]{32}$/', $requestId)) {
            $message .= ' '.sprintf((string) ($labels['reference'] ?? 'Reference: %s'), $requestId);
        }
        $success ? Message::addConfirmation($message) : Message::addError($message);
    }

    private function redirect(int $rootId): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('contao_backend', [
            'do' => 'page', 'act' => 'edit', 'id' => $rootId,
        ]).'#cmp-root-licence-panel');
    }
}
