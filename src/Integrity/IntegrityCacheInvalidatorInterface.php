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
 * Narrow cache invalidation after an integrity change.
 *
 * Only the affected root site is invalidated; a global flush is never issued by
 * this subsystem.
 */
interface IntegrityCacheInvalidatorInterface
{
    public function invalidateRoot(int $rootPageId): void;

    public function invalidatePage(int $pageId): void;
}
