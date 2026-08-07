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

namespace Vtinnovations\ContaoMultilingualPagetree\Backend;

/**
 * Why the backend is editing the default language instead of a requested one.
 *
 * Every fall back to the default language records a reason. Without it a denied
 * language and an unrequested language are indistinguishable, which is exactly
 * how "clicking the tab appears to do nothing" could survive several repairs:
 * the interface silently returned to the source language and said nothing.
 *
 * The reason is a category, never a message with record data in it, so it is
 * safe to log and safe to show.
 */
enum BackendLanguageFallback: string
{
    /** An additional language is active; nothing fell back. */
    case None = 'none';

    /** No language parameter was present, so the default language is correct. */
    case NotRequested = 'not_requested';

    /** The parameter was present but not a syntactically valid language code. */
    case InvalidParameter = 'invalid_parameter';

    /** The edited record could not be traced to a Contao website root. */
    case UnknownRoot = 'unknown_root';

    /** The requested language is the root's own default language. */
    case IsDefaultLanguage = 'is_default_language';

    /** No language record of this root carries that code. */
    case NotConfigured = 'not_configured';

    /** The language exists for this root but is not published. */
    case NotPublished = 'not_published';

    /** The language belongs to a different website root. */
    case ForeignRoot = 'foreign_root';

    /** The backend user may not edit this root. */
    case PermissionDenied = 'permission_denied';

    /** The licence gate does not permit translation editing for this root. */
    case LicenceDenied = 'licence_denied';

    /** The root has no domain, so the licence scope could not be selected. */
    case RootDomainMissing = 'root_domain_missing';

    public function isDenial(): bool
    {
        return match ($this) {
            self::PermissionDenied, self::LicenceDenied, self::RootDomainMissing => true,
            default => false,
        };
    }

    /** True when the editor asked for a language and did not get it. */
    public function wasRequested(): bool
    {
        return match ($this) {
            self::None, self::NotRequested => false,
            default => true,
        };
    }
}
