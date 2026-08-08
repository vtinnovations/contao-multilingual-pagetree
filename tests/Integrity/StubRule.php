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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Integrity;

use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityDataSourceInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssue;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;

final class StubRule implements IntegrityRuleInterface
{
    /**
     * @param list<string>         $entities
     * @param list<IntegrityIssue> $issues
     */
    public function __construct(
        private readonly string $name,
        private readonly int $priority,
        private readonly array $entities = [],
        private readonly array $issues = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getSupportedEntities(): array
    {
        return $this->entities;
    }

    public function isRepairable(): bool
    {
        return false;
    }

    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection
    {
        return new IntegrityIssueCollection($this->issues);
    }
}
