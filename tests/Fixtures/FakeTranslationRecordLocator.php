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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordLocatorInterface;

class FakeTranslationRecordLocator implements TranslationRecordLocatorInterface
{
    /** @var list<array{string, int, string, int|null}> */
    public array $calls = [];

    /**
     * @param array<string, object> $records keyed by "<table>|<id>|<language>"
     */
    public function __construct(private array $records = [])
    {
    }

    public function find(string $translationTable, int $sourceId, string $language, ?int $parentId = null): ?object
    {
        $this->calls[] = [$translationTable, $sourceId, $language, $parentId];

        return $this->records[$translationTable.'|'.$sourceId.'|'.$language] ?? null;
    }
}
