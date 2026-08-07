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

namespace Vtinnovations\ContaoMultilingualPagetree\Storage;

/**
 * Replay, idempotency and cross-node coordination for inbound server-to-server
 * requests.
 *
 * The ledger is the authority on "have I seen this exact request before?" and it
 * must be shared by every node of a clustered installation - otherwise one node
 * could apply something a second node has already rejected as a replay.
 */
interface RequestLedgerInterface
{
    /**
     * Atomically claims a request id.
     *
     * Returns null when the claim succeeded and processing may start, or the
     * existing entry when the id was already claimed, so the caller can
     * distinguish an idempotent repeat from a conflicting reuse.
     *
     * @throws RequestLedgerException when the ledger cannot answer; the caller
     *                                must then refuse the request rather than guess
     */
    public function claim(string $requestId, string $nonceDigest, string $fingerprint, int $now): ?LedgerEntry;

    /** Whether this nonce digest was already spent by a different request id. */
    public function isNonceSpent(string $nonceDigest, string $requestId): bool;

    /** Re-opens a claimed id whose previous attempt failed, for a genuine retry. */
    public function reclaim(string $requestId, string $fingerprint, int $now): void;

    /** Records the outcome of a claimed request. */
    public function complete(string $requestId, string $result, ?int $documentVersion, int $now): void;

    /** Removes entries older than the retention window. */
    public function prune(int $olderThan): void;

    /**
     * Runs the given work under a cluster-wide exclusive lock.
     *
     * Implementations must refuse rather than run the work unlocked.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     *
     * @throws RequestLedgerException
     */
    public function withLock(string $name, int $timeout, callable $work): mixed;
}
