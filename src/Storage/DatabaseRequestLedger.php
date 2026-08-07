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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Replay and idempotency state in the Contao database.
 *
 * The database is used rather than a file because it is the one store every node
 * of a clustered installation already shares transactionally. Claiming is an
 * INSERT against unique indexes on both the request id and the nonce digest, so
 * two nodes racing on the same inbound request cannot both win: one inserts, the
 * other gets a constraint violation and takes the duplicate path.
 */
final class DatabaseRequestLedger implements RequestLedgerInterface
{
    public const TABLE = 'tl_multilingual_pagetree_channel_ledger';

    /** Rows removed per prune call, so cleanup can never become a long lock. */
    private const PRUNE_LIMIT = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function claim(string $requestId, string $nonceDigest, string $fingerprint, int $now): ?LedgerEntry
    {
        $existing = $this->find($requestId);

        if (null !== $existing) {
            return $existing;
        }

        try {
            $this->connection->insert(self::TABLE, [
                'request_id' => $requestId,
                'nonce_digest' => $nonceDigest,
                'fingerprint' => $fingerprint,
                'result' => LedgerEntry::RESULT_CLAIMED,
                'document_version' => null,
                'claimed_at' => $now,
                'completed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Either another node claimed the same id in the meantime, or this
            // is a fresh id trying to reuse an already spent nonce.
            $raced = $this->find($requestId);

            if (null !== $raced) {
                return $raced;
            }

            throw new RequestLedgerException('The nonce was already used.');
        } catch (\Throwable $exception) {
            $this->logger?->error('Contao Multilingual Pagetree: the request ledger is unavailable.', ['reason' => $exception->getMessage()]);

            throw new RequestLedgerException('The request ledger is unavailable.', 0, $exception);
        }

        return null;
    }

    public function isNonceSpent(string $nonceDigest, string $requestId): bool
    {
        try {
            $found = $this->connection->fetchOne(
                'SELECT request_id FROM '.self::TABLE.' WHERE nonce_digest = ? LIMIT 1',
                [$nonceDigest],
            );
        } catch (\Throwable $exception) {
            throw new RequestLedgerException('The request ledger is unavailable.', 0, $exception);
        }

        if (!is_string($found)) {
            return false;
        }

        return !hash_equals($found, $requestId);
    }

    public function reclaim(string $requestId, string $fingerprint, int $now): void
    {
        try {
            $this->connection->update(
                self::TABLE,
                [
                    'fingerprint' => $fingerprint,
                    'result' => LedgerEntry::RESULT_CLAIMED,
                    'claimed_at' => $now,
                    'completed_at' => null,
                ],
                ['request_id' => $requestId],
            );
        } catch (\Throwable $exception) {
            throw new RequestLedgerException('The ledger entry could not be re-opened.', 0, $exception);
        }
    }

    public function complete(string $requestId, string $result, ?int $documentVersion, int $now): void
    {
        try {
            $this->connection->update(
                self::TABLE,
                [
                    'result' => $result,
                    'document_version' => $documentVersion,
                    'completed_at' => $now,
                ],
                ['request_id' => $requestId],
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('Contao Multilingual Pagetree: a ledger entry could not be completed.', ['reason' => $exception->getMessage()]);
        }
    }

    public function prune(int $olderThan): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM '.self::TABLE.' WHERE claimed_at < ? ORDER BY claimed_at ASC LIMIT '.self::PRUNE_LIMIT,
                [$olderThan],
            );
        } catch (\Throwable $exception) {
            $this->logger?->warning('Contao Multilingual Pagetree: ledger entries could not be pruned.', ['reason' => $exception->getMessage()]);
        }
    }

    /**
     * A cluster-wide advisory lock around the activation itself.
     *
     * MySQL and MariaDB - the databases Contao supports - provide `GET_LOCK`, so
     * two nodes cannot activate different packages at the same moment. If the
     * lock cannot be taken, the caller refuses rather than proceeding without it.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    public function withLock(string $name, int $timeout, callable $work): mixed
    {
        try {
            $acquired = 1 === (int) $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, $timeout]);
        } catch (\Throwable $exception) {
            $this->logger?->warning('Contao Multilingual Pagetree: the cluster lock is unavailable.', ['reason' => $exception->getMessage()]);

            throw new RequestLedgerException('The cluster lock is unavailable.', 0, $exception);
        }

        if (!$acquired) {
            throw new RequestLedgerException('The cluster lock is held by another node.');
        }

        try {
            return $work();
        } finally {
            try {
                $this->connection->executeStatement('SELECT RELEASE_LOCK(?)', [$name]);
            } catch (\Throwable $exception) {
                $this->logger?->warning('Contao Multilingual Pagetree: the cluster lock could not be released.', ['reason' => $exception->getMessage()]);
            }
        }
    }

    private function find(string $requestId): ?LedgerEntry
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT request_id, fingerprint, result, document_version, claimed_at, completed_at FROM '
                .self::TABLE.' WHERE request_id = ?',
                [$requestId],
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('Contao Multilingual Pagetree: the request ledger is unavailable.', ['reason' => $exception->getMessage()]);

            throw new RequestLedgerException('The request ledger is unavailable.', 0, $exception);
        }

        if (!is_array($row) || [] === $row) {
            return null;
        }

        return new LedgerEntry(
            (string) $row['request_id'],
            (string) $row['fingerprint'],
            (string) $row['result'],
            null === $row['document_version'] ? null : (int) $row['document_version'],
            (int) $row['claimed_at'],
            null === $row['completed_at'] ? null : (int) $row['completed_at'],
        );
    }
}
