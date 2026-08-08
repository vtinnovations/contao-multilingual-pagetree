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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Availability;

use Vtinnovations\ContaoMultilingualPagetree\Availability\ModelSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;

final class InMemoryModelSiteLanguageRegistry extends ModelSiteLanguageRegistry
{
    /** @param array<int, list<object>> $records
     *  @param array<int, string> $defaults
     */
    public function __construct(private array $records, private array $defaults)
    {
        parent::__construct(null, new CanonicalUrlPolicy());
    }

    protected function fetchLanguageRecords(int $rootPageId): iterable
    {
        return array_values(array_filter($this->records[$rootPageId] ?? [], static fn (object $record): bool => (bool) $record->published));
    }

    protected function fetchDefaultLanguage(int $rootPageId): ?string
    {
        return $this->defaults[$rootPageId] ?? null;
    }
}
