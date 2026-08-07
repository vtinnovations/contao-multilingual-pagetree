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

namespace Vtinnovations\ContaoMultilingualPagetree\Availability;

/**
 * Why a page translation could not be used.
 *
 * The reason is diagnostic information for logs and for the backend; it is
 * never rendered for visitors.
 */
enum PageAvailabilityReason: string
{
    case Available = 'available';
    case NoTranslation = 'no_translation';
    case Unpublished = 'unpublished';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case OrphanedRelation = 'orphaned_relation';
    case WrongRootSite = 'wrong_root_site';
    case WrongLanguage = 'wrong_language';
    case LanguageNotConfigured = 'language_not_configured';
    case InvalidAlias = 'invalid_alias';
    case SourcePageUnavailable = 'source_page_unavailable';
    case ResolutionFailed = 'resolution_failed';
}
