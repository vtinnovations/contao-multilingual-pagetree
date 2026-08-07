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

namespace Vtinnovations\ContaoMultilingualPagetree\Translation;

interface TranslationFieldPolicyContributorInterface
{
    /** @return iterable<TranslationFieldRegistration> */
    public function registrations(): iterable;
}
