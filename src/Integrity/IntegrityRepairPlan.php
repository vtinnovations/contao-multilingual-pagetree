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
 * An immutable, verifiable repair plan.
 *
 * The plan records the exact issues it was built from. Before execution the
 * scanner runs again and the plan is rejected when the underlying data changed,
 * so a stale plan can never delete something an editor did not see.
 */
final class IntegrityRepairPlan
{
    /**
     * @param list<IntegrityRepairAction> $actions
     * @param list<string>                $unresolved Issue codes that need manual work
     */
    public function __construct(
        public readonly IntegrityScope $scope,
        public readonly array $actions,
        public readonly string $checksum,
        public readonly array $unresolved = [],
        public readonly int $createdAt = 0,
    ) {
    }

    public static function create(IntegrityScope $scope, array $actions, array $unresolved = []): self
    {
        $actions = array_values(array_filter(
            $actions,
            static fn (mixed $action): bool => $action instanceof IntegrityRepairAction,
        ));

        return new self($scope, $actions, self::checksumOf($actions), array_values(array_unique($unresolved)), time());
    }

    /**
     * @param list<IntegrityRepairAction> $actions
     */
    public static function checksumOf(array $actions): string
    {
        $parts = [];

        foreach ($actions as $action) {
            $parts[] = $action->issueFingerprint.':'.$action->type;
        }

        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    public function isEmpty(): bool
    {
        return [] === $this->actions;
    }

    public function hasDestructiveActions(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->destructive) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<IntegrityRepairAction>
     */
    public function executableActions(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (IntegrityRepairAction $action): bool => $action->isExecutable(),
        ));
    }

    /**
     * Actions are executed in a dependency-safe order: normalisations first,
     * then quarantines, then deletions.
     *
     * @return list<IntegrityRepairAction>
     */
    public function orderedActions(): array
    {
        $weights = [
            IntegrityRepairAction::TYPE_NORMALISE => 0,
            IntegrityRepairAction::TYPE_QUARANTINE => 1,
            IntegrityRepairAction::TYPE_DELETE => 2,
        ];

        $actions = $this->executableActions();

        usort(
            $actions,
            static fn (IntegrityRepairAction $a, IntegrityRepairAction $b): int => [
                $weights[$a->type] ?? 9, $a->table, $a->recordId,
            ] <=> [
                $weights[$b->type] ?? 9, $b->table, $b->recordId,
            ],
        );

        return $actions;
    }

    /**
     * The preview an editor confirms. It never modifies data.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $deleted = 0;
        $quarantined = 0;
        $normalised = 0;

        foreach ($this->executableActions() as $action) {
            match ($action->type) {
                IntegrityRepairAction::TYPE_DELETE => ++$deleted,
                IntegrityRepairAction::TYPE_QUARANTINE => ++$quarantined,
                default => ++$normalised,
            };
        }

        return [
            'scope' => $this->scope->toArray(),
            'checksum' => $this->checksum,
            'actions' => array_map(
                static fn (IntegrityRepairAction $action): array => $action->toArray(),
                $this->orderedActions(),
            ),
            'recordsDeleted' => $deleted,
            'recordsQuarantined' => $quarantined,
            'recordsNormalised' => $normalised,
            'destructive' => $this->hasDestructiveActions(),
            'unresolved' => $this->unresolved,
        ];
    }
}
