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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Security;

final class PermissionUser
{
    /** @param list<int> $pageMounts */
    public function __construct(
        public readonly bool $isAdmin,
        private readonly bool $siteStructure,
        private readonly array $pageMounts,
        private readonly array $fields = [],
    ) {
    }

    public function hasAccess(string $value, string $category): bool
    {
        return match ($category) {
            'modules' => 'page' === $value && $this->siteStructure,
            'pagemounts' => in_array((int) $value, $this->pageMounts, true),
            'alexf' => in_array($value, $this->fields, true),
            default => false,
        };
    }
}
