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
 * Why a resource is (not) available in a target language.
 *
 * Diagnostic information for logs and templates; never rendered for visitors.
 */
enum ResourceAvailabilityReason: string
{
    case Available = 'available';

    /** The current request does not address a detail record at all. */
    case NotADetailResource = 'not_a_detail_resource';

    case NoCurrentPage = 'no_current_page';
    case LanguageNotConfigured = 'language_not_configured';
    case PageUnavailable = 'page_unavailable';
    case MissingDetailRecord = 'missing_detail_record';
    case MissingDetailTranslation = 'missing_detail_translation';
    case UnpublishedDetailTranslation = 'unpublished_detail_translation';
    case InvalidDetailAlias = 'invalid_detail_alias';
    case UnrepresentableParameters = 'unrepresentable_parameters';
    case ResolutionFailed = 'resolution_failed';
}
