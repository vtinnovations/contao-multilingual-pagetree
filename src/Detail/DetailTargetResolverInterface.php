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

namespace Vtinnovations\ContaoMultilingualPagetree\Detail;

use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the detail record addressed by the current request and its target
 * URL in another language (point 3).
 */
interface DetailTargetResolverInterface
{
    public function detect(Request $request, PageModel $readerPage): ?DetailContext;

    public function resolveTarget(Request $request, PageModel $readerPage, string $targetLanguage): DetailTargetResult;

    public function resolveTargetUrl(Request $request, PageModel $readerPage, string $targetLanguage): ?string;
}
