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
 * One repair step proposed for one issue.
 *
 * Actions are data, not behaviour: the same action list is used for the preview
 * and for the execution, so the two can never diverge.
 */
final class IntegrityRepairAction
{
    /** Replace invalid metadata with a safe normalised value. */
    public const TYPE_NORMALISE = 'normalise';

    /** Make an invalid record inactive without deleting it. */
    public const TYPE_QUARANTINE = 'quarantine';

    /** Remove a record that cannot function and carries no unique information. */
    public const TYPE_DELETE = 'delete';

    /** Nothing can be done automatically; an editor must decide. */
    public const TYPE_MANUAL = 'manual';

    /**
     * @param array<string, scalar|null> $changes Column => new value for a normalise action
     */
    public function __construct(
        public readonly string $type,
        public readonly string $table,
        public readonly int $recordId,
        public readonly int $rootPageId,
        public readonly string $language,
        public readonly string $issueCode,
        public readonly string $issueFingerprint,
        public readonly bool $destructive = false,
        public readonly array $changes = [],
        public readonly string $reason = '',
    ) {
    }

    public static function fromIssue(IntegrityIssue $issue, string $type, array $changes = [], ?bool $destructive = null): self
    {
        return new self(
            $type,
            $issue->table,
            $issue->recordId,
            $issue->rootPageId,
            $issue->language,
            $issue->code,
            $issue->fingerprint(),
            $destructive ?? (self::TYPE_DELETE === $type),
            $changes,
            $issue->code,
        );
    }

    public function isExecutable(): bool
    {
        return self::TYPE_MANUAL !== $this->type && $this->recordId > 0 && '' !== $this->table;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'table' => $this->table,
            'record' => $this->recordId,
            'root' => $this->rootPageId,
            'language' => $this->language,
            'issue' => $this->issueCode,
            'destructive' => $this->destructive,
            'changes' => array_keys($this->changes),
        ];
    }
}
