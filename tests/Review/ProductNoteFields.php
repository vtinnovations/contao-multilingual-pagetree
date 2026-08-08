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

final class ProductNoteFields implements TranslationFieldPolicyContributorInterface
{
    public function registrations(): iterable
    {
        yield new TranslationFieldRegistration('tl_content', 'note', 'string', 'product_note');
    }
}
