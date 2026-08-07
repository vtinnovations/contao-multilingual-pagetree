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

use Psr\Log\LoggerInterface;

/**
 * Deterministic registry of integrity rules.
 *
 * Ordering depends only on priority and rule name, never on service definition
 * or DCA load order. Objects that do not implement the rule interface, or whose
 * metadata cannot be read, are ignored instead of breaking discovery.
 */
final class IntegrityRuleRegistry
{
    /** @var list<IntegrityRuleInterface>|null */
    private ?array $sorted = null;

    /**
     * @param iterable<object> $rules
     */
    public function __construct(
        private readonly iterable $rules = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return list<IntegrityRuleInterface>
     */
    public function all(): array
    {
        if (null !== $this->sorted) {
            return $this->sorted;
        }

        $rules = [];

        foreach ($this->rules as $rule) {
            if (!$rule instanceof IntegrityRuleInterface) {
                continue;
            }

            try {
                $name = $rule->getName();
                $priority = $rule->getPriority();
            } catch (\Throwable $exception) {
                $this->logger?->error('Contao Multilingual Pagetree: an integrity rule could not be read: '.$exception->getMessage());

                continue;
            }

            if (!is_string($name) || '' === $name) {
                continue;
            }

            $rules[] = ['rule' => $rule, 'name' => $name, 'priority' => $priority];
        }

        usort(
            $rules,
            static fn (array $a, array $b): int => [$b['priority'], $a['name']] <=> [$a['priority'], $b['name']],
        );

        return $this->sorted = array_map(
            static fn (array $entry): IntegrityRuleInterface => $entry['rule'],
            $rules,
        );
    }

    /**
     * @return list<IntegrityRuleInterface>
     */
    public function forScope(IntegrityScope $scope): array
    {
        $rules = [];

        foreach ($this->all() as $rule) {
            try {
                $entities = $rule->getSupportedEntities();
            } catch (\Throwable) {
                continue;
            }

            if (null === $scope->entityType || [] === $entities || in_array($scope->entityType, $entities, true)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn (IntegrityRuleInterface $rule): string => $rule->getName(),
            $this->all(),
        );
    }
}
