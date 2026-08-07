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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\Storage\LedgerEntry;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RequestLedgerException;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RequestLedgerInterface;

/**
 * An in-memory ledger with the same claim/replay semantics as the database one.
 */
final class InMemoryRequestLedger implements RequestLedgerInterface
{
    /** @var array<string, LedgerEntry> */
    public array $entries = [];

    /** @var array<string, string> nonce digest => request id */
    public array $nonces = [];

    public bool $unavailable = false;
    public bool $lockHeld = false;
    public int $pruned = 0;

    public function claim(string $requestId, string $nonceDigest, string $fingerprint, int $now): ?LedgerEntry
    {
        if ($this->unavailable) {
            throw new RequestLedgerException('unavailable');
        }

        if (isset($this->entries[$requestId])) {
            return $this->entries[$requestId];
        }

        if (isset($this->nonces[$nonceDigest]) && $this->nonces[$nonceDigest] !== $requestId) {
            throw new RequestLedgerException('nonce reused');
        }

        $this->nonces[$nonceDigest] = $requestId;
        $this->entries[$requestId] = new LedgerEntry($requestId, $fingerprint, LedgerEntry::RESULT_CLAIMED, null, $now, null);

        return null;
    }

    public function isNonceSpent(string $nonceDigest, string $requestId): bool
    {
        if ($this->unavailable) {
            throw new RequestLedgerException('unavailable');
        }

        return isset($this->nonces[$nonceDigest]) && $this->nonces[$nonceDigest] !== $requestId;
    }

    public function reclaim(string $requestId, string $fingerprint, int $now): void
    {
        $this->entries[$requestId] = new LedgerEntry($requestId, $fingerprint, LedgerEntry::RESULT_CLAIMED, null, $now, null);
    }

    public function complete(string $requestId, string $result, ?int $documentVersion, int $now): void
    {
        $existing = $this->entries[$requestId] ?? null;

        if (null === $existing) {
            return;
        }

        $this->entries[$requestId] = new LedgerEntry(
            $requestId,
            $existing->fingerprint,
            $result,
            $documentVersion,
            $existing->claimedAt,
            $now,
        );
    }

    public function prune(int $olderThan): void
    {
        ++$this->pruned;
    }

    public function withLock(string $name, int $timeout, callable $work): mixed
    {
        if ($this->lockHeld) {
            throw new RequestLedgerException('lock held');
        }

        return $work();
    }
}
