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

namespace Vtinnovations\ContaoMultilingualPagetree\Security;

/**
 * The capabilities of this bundle that depend on a valid registration.
 *
 * The values are the feature identifiers exchanged on the distribution channel;
 * they are part of the wire contract and must not be renamed without a
 * coordinated change on the issuing side.
 *
 * Deliberately absent: everything a visitor sees. Frontend routing, the language
 * switcher, canonical and `hreflang` metadata and the rendering of already
 * translated content are never gated, because a registration problem must never
 * take a live website down. What is gated is the editorial capability that
 * creates and maintains multilingual data.
 */
enum Capability: string
{
    /** Creating and changing translation records and their field states. */
    case TranslationEditing = 'translation_editing';

    /** Marking translations reviewed and resolving review state. */
    case TranslationReview = 'translation_review';

    /** Free language content trees, including the connected-to-free import. */
    case FreeContentMode = 'free_content_mode';

    /** Executing integrity repairs and deletion cascades. Scanning stays free. */
    case IntegrityRepair = 'integrity_repair';

    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
