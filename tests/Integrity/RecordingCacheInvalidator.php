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

use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityCacheInvalidatorInterface;

final class RecordingCacheInvalidator implements IntegrityCacheInvalidatorInterface
{
    /** @var list<int> */
    public array $roots = [];

    /** @var list<int> */
    public array $pages = [];

    public function invalidateRoot(int $rootPageId): void
    {
        $this->roots[] = $rootPageId;
    }

    public function invalidatePage(int $pageId): void
    {
        $this->pages[] = $pageId;
    }
}
