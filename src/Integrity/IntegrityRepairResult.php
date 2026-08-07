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

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity;

/**
 * Structured outcome of a repair execution.
 *
 * A partial completion is reported precisely; full success is never claimed
 * when an action failed.
 */
final class IntegrityRepairResult
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_STALE_PLAN = 'stale_plan';
    public const STATUS_DENIED = 'denied';
    public const STATUS_INVALID_TOKEN = 'invalid_token';
    public const STATUS_NOTHING_TO_DO = 'nothing_to_do';

    /**
     * @param list<string> $failedActions
     */
    public function __construct(
        public readonly string $status,
        public readonly int $deleted = 0,
        public readonly int $quarantined = 0,
        public readonly int $normalised = 0,
        public readonly array $failedActions = [],
        public readonly bool $cacheInvalidated = false,
    ) {
    }

    public function isSuccessful(): bool
    {
        return self::STATUS_COMPLETED === $this->status || self::STATUS_NOTHING_TO_DO === $this->status;
    }

    public function changedRecords(): int
    {
        return $this->deleted + $this->quarantined + $this->normalised;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'deleted' => $this->deleted,
            'quarantined' => $this->quarantined,
            'normalised' => $this->normalised,
            'failed' => $this->failedActions,
            'cacheInvalidated' => $this->cacheInvalidated,
        ];
    }
}
