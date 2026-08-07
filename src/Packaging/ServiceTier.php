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

namespace Vtinnovations\ContaoMultilingualPagetree\Packaging;

/**
 * The tier a signed document carries.
 *
 * A tier grants a baseline set of feature identifiers. The document's explicit
 * feature list may grant more, never less: the entitlement is the union of the
 * baseline and the signed list, so a single installation can receive an extra
 * feature without inventing a new tier.
 *
 * The values are part of the wire contract.
 */
enum ServiceTier: string
{
    case Free = 'free';
    case Pro = 'pro';

    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * The feature identifiers included in the tier itself.
     *
     * @return list<string>
     */
    public function baselineFeatures(): array
    {
        return match ($this) {
            self::Free => [
                'translation_editing',
                'translation_review',
            ],
            self::Pro => [
                'translation_editing',
                'translation_review',
                'free_content_mode',
                'integrity_repair',
            ],
        };
    }
}
