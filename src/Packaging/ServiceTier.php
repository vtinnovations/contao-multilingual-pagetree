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
 * This product is issued under one model: a lifetime free entitlement. There is
 * exactly one accepted package value, and it grants every feature the product
 * has. "Free" describes the price, nothing else - the entitlement still has to
 * be activated, signed, host-bound and periodically re-verified like any other.
 *
 * The cases are the accepted-package allowlist, deliberately: a document naming
 * any other package is refused while it is still being parsed, so a package this
 * product is not sold under can never reach an entitlement decision. Keeping the
 * allowlist in one enum rather than restating it beside each check is what stops
 * the two from drifting apart.
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

    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * The feature identifiers included in the tier itself.
     *
     * Every capability this release knows is listed here: the product has no
     * paid tier above this one, so a valid entitlement withholds nothing.
     *
     * @return list<string>
     */
    public function baselineFeatures(): array
    {
        return match ($this) {
            self::Free => [
                'translation_editing',
                'translation_review',
                'free_content_mode',
                'integrity_repair',
            ],
        };
    }
}
