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

namespace Vtinnovations\ContaoMultilingualPagetree\Distribution;

use Psr\Log\LoggerInterface;

/**
 * The two documented server-to-server signals, both fire-and-forget.
 *
 * The per-invocation signal carries exactly the product name and the normalised
 * host the installation answers on:
 *
 *     {"project":"<project>","domain":"<host>"}
 *
 * The separate module-entry signal carries exactly the normalised host and the
 * issued key of an already authenticated local record:
 *
 *     {"domain":"<host>","key":"<issued-key>"}
 *
 * They are distinct events and are never merged into one broader packet. No
 * document, digest, signature, user, session, cookie, IP list, path,
 * environment value, request body, database content or arbitrary header is ever
 * included in either. Nothing is read back, nothing is stored, nothing is
 * printed, and a failure never affects the request or the entitlement state.
 *
 * The key of the module-entry event stays server side: it is never written to a
 * log - not even at debug level - never rendered, and never handed to the
 * browser. It is the single documented exception to "the key never leaves
 * activation and refresh", and it exists so misuse of a copied key on a
 * different host is visible to the issuer.
 *
 * Both are genuine server-to-server communication and are documented as such in
 * `docs/PRODUCT-REGISTRATION.en.md`; neither is disguised as anything else.
 */
final class UsageSignal
{
    private bool $sent = false;

    /**
     * The module-entry event claimed by this request, held in memory only until
     * the response has been sent.
     *
     * @var array{domain: string, key: string}|null
     */
    private ?array $queuedEntry = null;

    public function __construct(
        private readonly ChannelTransportInterface $transport,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Sends the signal at most once per PHP invocation.
     */
    public function send(string $host): void
    {
        if ($this->sent || '' === $host) {
            return;
        }

        $this->sent = true;

        try {
            $this->transport->postJsonWithoutResponse(
                ChannelAddress::signal(),
                json_encode(
                    ['project' => ProductProfile::PROJECT, 'domain' => $host],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                ProductProfile::SIGNAL_CONNECT_TIMEOUT,
                ProductProfile::SIGNAL_TOTAL_TIMEOUT,
            );
        } catch (\Throwable $exception) {
            // Deliberately swallowed: the signal is never allowed to influence
            // the response, the entitlement or the visitor.
            $this->logger?->debug('Contao Multilingual Pagetree: the usage signal could not be delivered.', [
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Records a module-entry event for delivery after the response.
     *
     * The caller has already claimed the session marker, so this method is
     * reached at most once per authenticated session even when several tabs
     * render at the same time. Nothing is sent yet: delivery is deferred so a
     * slow endpoint cannot delay what the editor sees.
     */
    public function queueModuleEntry(string $host, string $key): void
    {
        if ('' === $host || '' === $key) {
            return;
        }

        $this->queuedEntry = ['domain' => $host, 'key' => $key];
    }

    /**
     * Delivers a queued module-entry event, once.
     *
     * The queue is cleared before the call, so a timeout or an endpoint failure
     * cannot cause a second attempt later in the same session.
     */
    public function deliverQueuedModuleEntry(): void
    {
        $payload = $this->queuedEntry;
        $this->queuedEntry = null;

        if (null === $payload) {
            return;
        }

        try {
            $this->transport->postJsonWithoutResponse(
                ChannelAddress::signal(),
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ProductProfile::SIGNAL_CONNECT_TIMEOUT,
                ProductProfile::SIGNAL_TOTAL_TIMEOUT,
            );
        } catch (\Throwable) {
            // Deliberately silent and deliberately without context: the payload
            // holds the issued key, so not even a redacted reason is recorded.
            $this->logger?->debug('Contao Multilingual Pagetree: a module-entry signal could not be delivered.');
        }
    }

    /** Test and diagnostics helper: whether this invocation already signalled. */
    public function hasSent(): bool
    {
        return $this->sent;
    }

    /** Test helper: whether a module-entry event is waiting for delivery. */
    public function hasQueuedModuleEntry(): bool
    {
        return null !== $this->queuedEntry;
    }

    /** Resets the once-per-invocation guard between long-running worker cycles. */
    public function reset(): void
    {
        $this->sent = false;
        $this->queuedEntry = null;
    }
}
