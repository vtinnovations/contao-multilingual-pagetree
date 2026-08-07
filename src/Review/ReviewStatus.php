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

namespace Vtinnovations\ContaoMultilingualPagetree\Review;

/**
 * Editorial review state of one translation record.
 *
 * The state is editorial metadata only: it never influences routing,
 * availability, publication or frontend rendering.
 */
enum ReviewStatus: string
{
    /** Never explicitly reviewed, or no reliable baseline exists. */
    case Unreviewed = 'unreviewed';

    /** Reviewed against exactly the current source state. */
    case UpToDate = 'up_to_date';

    /** Reviewed earlier, but a translatable source field changed afterwards. */
    case NeedsReview = 'needs_review';

    /**
     * Internal state for an invalid relation. It is shown as a warning and
     * never offers a review action; editors are not asked to understand it.
     */
    case SourceMissing = 'source_missing';

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            return self::Unreviewed;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Unreviewed;
    }

    /**
     * States an editor selects between in list filters.
     *
     * @return list<string>
     */
    public static function editorialValues(): array
    {
        return [self::Unreviewed->value, self::NeedsReview->value, self::UpToDate->value];
    }

    public function needsAttention(): bool
    {
        return self::NeedsReview === $this || self::Unreviewed === $this;
    }
}
