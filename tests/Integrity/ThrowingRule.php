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
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;

final class ThrowingRule implements IntegrityRuleInterface
{
    public function getName(): string
    {
        return 'broken';
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function getSupportedEntities(): array
    {
        return [];
    }

    public function isRepairable(): bool
    {
        return false;
    }

    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection
    {
        throw new \RuntimeException('Third-party rule failure');
    }
}
