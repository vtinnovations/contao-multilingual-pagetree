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
use Vtinnovations\ContaoMultilingualPagetree\Helper\Clock;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\InstallationIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageFormatException;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageReader;
use Vtinnovations\ContaoMultilingualPagetree\Security\ChannelRequestRejected;
use Vtinnovations\ContaoMultilingualPagetree\Security\ChannelRequestVerifier;
use Vtinnovations\ContaoMultilingualPagetree\Storage\LedgerEntry;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RequestLedgerException;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RequestLedgerInterface;

/**
 * Everything that happens behind the public update path, once the request shape
 * itself has been accepted.
 *
 * The order is: authenticate, then reserve the request id, then verify the
 * package, then apply it. Nothing is written before all of that succeeds, and a
 * request that was already applied is answered idempotently instead of being
 * applied twice.
 *
 * The handler never writes to a path taken from the request, never generates
 * code and never touches application source: the only thing it can change is the
 * private state pair below `var/`.
 */
final class ChannelUpdateProcessor
{
    /** A claim older than this is treated as an abandoned attempt. */
    private const STALE_CLAIM = 120;

    public function __construct(
        private readonly ChannelRequestVerifier $verifier,
        private readonly PackageReader $reader,
        private readonly PackageActivator $activator,
        private readonly RequestLedgerInterface $ledger,
        private readonly InstallationIdentity $identity,
        private readonly Clock $clock,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RootDomainRegistry $rootDomains = null,
        private readonly ?RootScope $rootContext = null,
    ) {
    }

    /**
     * @param array<string, string|null> $headers
     */
    public function process(string $method, string $path, array $headers, string $rawBody): ChannelUpdateResult
    {
        $now = $this->clock->now();

        try {
            $request = $this->verifier->verify($method, $path, $headers, $rawBody, $now);
        } catch (ChannelRequestRejected $rejection) {
            $this->logger?->warning('Contao Multilingual Pagetree: an inbound channel request was rejected.', [
                'category' => $rejection->category,
            ]);

            return ChannelUpdateResult::unauthorized();
        }

        if (null !== $this->rootDomains && null !== $this->rootContext) {
            $matches = $this->rootDomains->rootsForExactDomain($request->host);
            if (1 !== count($matches)) {
                return ChannelUpdateResult::forbidden();
            }
            $this->rootContext->select($matches[0], $request->host);
        }

        $host = $this->identity->current();

        if (null === $host) {
            return ChannelUpdateResult::forbidden();
        }

        try {
            if ($this->ledger->isNonceSpent($request->nonceDigest, $request->requestId)) {
                $this->logger?->warning('Contao Multilingual Pagetree: an inbound channel request reused a spent nonce.');

                return ChannelUpdateResult::unauthorized();
            }

            $existing = $this->ledger->claim($request->requestId, $request->nonceDigest, $request->fingerprint, $now);

            if (null !== $existing) {
                $decision = $this->reconcile($existing, $request->requestId, $request->fingerprint, $now);

                if (null !== $decision) {
                    return $decision;
                }
            }
        } catch (RequestLedgerException $exception) {
            $this->logger?->error('Contao Multilingual Pagetree: the request ledger refused an inbound request.', [
                'reason' => $exception->getMessage(),
            ]);

            return ChannelUpdateResult::unavailable();
        }

        return $this->apply($request->requestId, $request->host, $host, $request->payloadBase64, $request->seal, $now);
    }

    /**
     * Decides what to do with an id that was claimed before.
     *
     * Returns a result when the request is finished, or null when processing may
     * continue because this is a genuine retry of an unfinished attempt.
     */
    private function reconcile(LedgerEntry $entry, string $requestId, string $fingerprint, int $now): ?ChannelUpdateResult
    {
        if (!$entry->matches($fingerprint)) {
            // The same id with different authenticated content is a security
            // event, not a retry: it is refused and never applied.
            $this->logger?->error('Contao Multilingual Pagetree: an inbound request reused an id with different content.');

            return ChannelUpdateResult::conflict($requestId);
        }

        if ($entry->isApplied()) {
            return ChannelUpdateResult::alreadyProcessed($requestId, $entry->documentVersion);
        }

        if (LedgerEntry::RESULT_CLAIMED === $entry->result && $entry->claimedAt > $now - self::STALE_CLAIM) {
            // Another node is working on exactly this request right now.
            return ChannelUpdateResult::unavailable();
        }

        $this->ledger->reclaim($requestId, $fingerprint, $now);

        return null;
    }

    /**
     * @param array<array-key, mixed>|null $seal
     */
    private function apply(string $requestId, string $packetHost, string $trustedHost, mixed $payloadBase64, ?array $seal, int $now): ChannelUpdateResult
    {
        try {
            $package = $this->reader->readPackage($payloadBase64, $seal, $now);
        } catch (PackageFormatException $exception) {
            $this->ledger->complete($requestId, LedgerEntry::RESULT_FAILED, null, $now);
            $this->logger?->warning('Contao Multilingual Pagetree: an inbound package failed verification.', [
                'reason' => $exception->getMessage(),
            ]);

            return ChannelUpdateResult::unauthorized();
        }

        // A pushed package must carry the signed host set. Accepting one without
        // it would let a replayed pre-upgrade document overwrite current state
        // with a binding this installation would then have to guess at.
        if ($package->document->isLegacyHostBinding()) {
            $this->ledger->complete($requestId, LedgerEntry::RESULT_FAILED, null, $now);

            return ChannelUpdateResult::unauthorized();
        }

        $outcome = $this->activator->apply($package, $trustedHost, $packetHost, $now);
        $version = $package->document->version;

        if ($outcome->isCurrent()) {
            $this->ledger->complete($requestId, LedgerEntry::RESULT_APPLIED, $version, $now);
            $this->ledger->prune($now - ProductProfile::LEDGER_RETENTION);

            return $outcome->isApplied()
                ? ChannelUpdateResult::updated($requestId, $version)
                : ChannelUpdateResult::alreadyProcessed($requestId, $version);
        }

        $this->ledger->complete($requestId, LedgerEntry::RESULT_FAILED, null, $now);

        return match ($outcome) {
            ActivationOutcome::HostMismatch => ChannelUpdateResult::forbidden(),
            ActivationOutcome::Older, ActivationOutcome::Conflict => ChannelUpdateResult::conflict($requestId),
            default => ChannelUpdateResult::unavailable(),
        };
    }
}
