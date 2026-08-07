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
 * An ordered, immutable set of integrity issues.
 */
final class IntegrityIssueCollection implements \IteratorAggregate, \Countable
{
    /** @var list<IntegrityIssue> */
    private array $issues;

    /**
     * @param list<IntegrityIssue> $issues
     */
    public function __construct(array $issues = [])
    {
        $this->issues = array_values(array_filter(
            $issues,
            static fn (mixed $issue): bool => $issue instanceof IntegrityIssue,
        ));
    }

    public function with(IntegrityIssue ...$issues): self
    {
        return new self([...$this->issues, ...$issues]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->issues, ...$other->all()]);
    }

    /**
     * @return list<IntegrityIssue>
     */
    public function all(): array
    {
        return $this->issues;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->issues);
    }

    public function count(): int
    {
        return count($this->issues);
    }

    public function isEmpty(): bool
    {
        return [] === $this->issues;
    }

    public function filterSeverity(IntegritySeverity $minimum): self
    {
        return new self(array_values(array_filter(
            $this->issues,
            static fn (IntegrityIssue $issue): bool => $issue->severity->isAtLeast($minimum),
        )));
    }

    public function filterEntity(string $entityType): self
    {
        return new self(array_values(array_filter(
            $this->issues,
            static fn (IntegrityIssue $issue): bool => $issue->entityType === $entityType,
        )));
    }

    public function filterCode(string $code): self
    {
        return new self(array_values(array_filter(
            $this->issues,
            static fn (IntegrityIssue $issue): bool => $issue->code === $code,
        )));
    }

    public function repairable(): self
    {
        return new self(array_values(array_filter(
            $this->issues,
            static fn (IntegrityIssue $issue): bool => $issue->isRepairable(),
        )));
    }

    /**
     * @return array<string, int> severity value => count
     */
    public function countsBySeverity(): array
    {
        $counts = [];

        foreach (IntegritySeverity::cases() as $severity) {
            $counts[$severity->value] = 0;
        }

        foreach ($this->issues as $issue) {
            ++$counts[$issue->severity->value];
        }

        return $counts;
    }

    /**
     * @return array<string, int> entity type => count
     */
    public function countsByEntity(): array
    {
        $counts = [];

        foreach ($this->issues as $issue) {
            $counts[$issue->entityType] = ($counts[$issue->entityType] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function highestSeverity(): IntegritySeverity
    {
        $highest = IntegritySeverity::Info;

        foreach ($this->issues as $issue) {
            if ($issue->severity->weight() > $highest->weight()) {
                $highest = $issue->severity;
            }
        }

        return $highest;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (IntegrityIssue $issue): array => $issue->toArray(), $this->issues);
    }
}
