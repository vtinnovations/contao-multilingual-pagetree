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
 * What a cascade would remove, per table.
 *
 * The plan is produced first and can be shown as a dry run before anything is
 * executed. It only ever contains bundle-managed translation records: source
 * records, free content and another site's data are never part of a cascade.
 */
final class CascadePlan
{
    /**
     * @param array<string, list<int>> $records Table => record ids
     * @param list<string>             $retained Tables deliberately left untouched
     */
    public function __construct(
        public readonly array $records = [],
        public readonly array $retained = [],
        public readonly int $rootPageId = 0,
        public readonly string $language = '',
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total();
    }

    public function total(): int
    {
        $total = 0;

        foreach ($this->records as $ids) {
            $total += count($ids);
        }

        return $total;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->records as $table => $ids) {
            $counts[$table] = count($ids);
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->rootPageId,
            'language' => $this->language,
            'total' => $this->total(),
            'counts' => $this->counts(),
            'retained' => $this->retained,
        ];
    }
}
