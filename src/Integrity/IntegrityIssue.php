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
 * One structured integrity finding.
 *
 * The issue carries everything the backend, the CLI and a repair planner need.
 * It never carries SQL, stack traces or translated content: the description is a
 * translation key plus safe parameters.
 */
final class IntegrityIssue
{
    public const REPAIR_NONE = 'none';
    public const REPAIR_AUTOMATIC = 'automatic';
    public const REPAIR_CONFIRMATION = 'confirmation';
    public const REPAIR_MANUAL = 'manual';

    /**
     * @param array<string, scalar|null> $context     Safe, non-sensitive detail values
     * @param list<int>                  $relatedIds  Further records involved (e.g. a cycle)
     */
    public function __construct(
        public readonly string $code,
        public readonly IntegritySeverity $severity,
        public readonly string $entityType,
        public readonly string $table,
        public readonly int $recordId,
        public readonly int $rootPageId,
        public readonly string $language,
        public readonly string $repairability = self::REPAIR_NONE,
        public readonly bool $destructive = false,
        public readonly ?string $sourceTable = null,
        public readonly ?int $sourceId = null,
        public readonly array $context = [],
        public readonly array $relatedIds = [],
    ) {
    }

    public function isRepairable(): bool
    {
        return self::REPAIR_NONE !== $this->repairability && self::REPAIR_MANUAL !== $this->repairability;
    }

    public function isAutomatic(): bool
    {
        return self::REPAIR_AUTOMATIC === $this->repairability;
    }

    public function requiresConfirmation(): bool
    {
        return self::REPAIR_CONFIRMATION === $this->repairability;
    }

    /**
     * A stable identity used to match a planned action against a rescan, so a
     * stale repair plan can be rejected.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->code,
            $this->table,
            (string) $this->recordId,
            (string) $this->rootPageId,
            $this->language,
            (string) ($this->sourceTable ?? ''),
            (string) ($this->sourceId ?? ''),
            implode(',', $this->relatedIds),
        ]));
    }

    /**
     * Structured, non-sensitive representation for CLI and logs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'entity' => $this->entityType,
            'table' => $this->table,
            'record' => $this->recordId,
            'root' => $this->rootPageId,
            'language' => $this->language,
            'sourceTable' => $this->sourceTable,
            'sourceId' => $this->sourceId,
            'repairability' => $this->repairability,
            'destructive' => $this->destructive,
            'related' => $this->relatedIds,
            'context' => $this->context,
        ];
    }
}
