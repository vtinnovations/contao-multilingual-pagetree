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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Review;

use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldPolicyContributorInterface;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistration;

final class ProtectedFieldContributor implements TranslationFieldPolicyContributorInterface
{
    public function registrations(): iterable
    {
        // Structural fields can never be reclassified as translatable.
        yield new TranslationFieldRegistration('tl_content', 'colPos', 'string', 'text');
    }
}
