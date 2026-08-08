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

use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityReason;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailContext;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailTargetResolverInterface;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailTargetResult;

/**
 * In-memory detail resolution (point 3) for switcher and metadata tests.
 */
class FakeDetailTargetResolver implements DetailTargetResolverInterface
{
    /**
     * @param array<string, DetailTargetResult> $targets keyed by target language
     */
    public function __construct(
        private readonly ?DetailContext $context = null,
        private readonly array $targets = [],
        private readonly ResourceAvailabilityReason $missingReason = ResourceAvailabilityReason::MissingDetailTranslation,
    ) {
    }

    /**
     * A request that does not address a detail record at all.
     */
    public static function none(): self
    {
        return new self();
    }

    public function detect(Request $request, PageModel $readerPage): ?DetailContext
    {
        return $this->context;
    }

    public function resolveTarget(Request $request, PageModel $readerPage, string $targetLanguage): DetailTargetResult
    {
        if (null === $this->context) {
            return DetailTargetResult::unavailable(ResourceAvailabilityReason::NotADetailResource);
        }

        return $this->targets[$targetLanguage] ?? DetailTargetResult::unavailable($this->missingReason);
    }

    public function resolveTargetUrl(Request $request, PageModel $readerPage, string $targetLanguage): ?string
    {
        return $this->resolveTarget($request, $readerPage, $targetLanguage)->url;
    }
}
