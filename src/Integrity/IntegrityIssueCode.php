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

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity;

/**
 * Stable issue codes.
 *
 * The values are part of the public contract of the integrity subsystem: they
 * appear in tests, logs, CLI output and translated backend labels, so they must
 * never change once released.
 */
final class IntegrityIssueCode
{
    // Language configuration
    public const INVALID_LANGUAGE_CONFIGURATION = 'invalid_language_configuration';
    public const DUPLICATE_LANGUAGE_CONFIGURATION = 'duplicate_language_configuration';
    public const MULTIPLE_FALLBACK_LANGUAGES = 'multiple_fallback_languages';
    public const MISSING_FALLBACK_LANGUAGE = 'missing_fallback_language';
    public const INVALID_ROOT_RELATION = 'invalid_root_relation';

    // Connected translation relations
    public const MISSING_SOURCE = 'missing_source';
    public const SELF_REFERENTIAL_SOURCE = 'self_referential_source';
    public const TRANSLATION_SOURCE_RELATION = 'translation_source_relation';
    public const CROSS_SITE_RELATION = 'cross_site_relation';
    public const CROSS_LANGUAGE_RELATION = 'cross_language_relation';
    public const DUPLICATE_TRANSLATION = 'duplicate_translation';
    public const ORPHANED_CONNECTED_TRANSLATION = 'orphaned_connected_translation';

    // Free content
    public const ORPHANED_FREE_CONTENT = 'orphaned_free_content';
    public const INVALID_FREE_PARENT = 'invalid_free_parent';
    public const FREE_CONTENT_CYCLE = 'free_content_cycle';

    // Metadata
    public const INVALID_FIELD_STATES = 'invalid_field_states';
    public const INVALID_REVIEW_METADATA = 'invalid_review_metadata';

    // Routing and publication
    public const INVALID_ALIAS = 'invalid_alias';
    public const DUPLICATE_ALIAS = 'duplicate_alias';
    public const INVALID_PUBLICATION_RANGE = 'invalid_publication_range';

    // Informational states
    public const INACTIVE_CONNECTED_DATA = 'inactive_connected_data';
    public const INACTIVE_FREE_DATA = 'inactive_free_data';

    /** A rule itself failed; scanning continued with the remaining rules. */
    public const RULE_FAILURE = 'rule_failure';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        try {
            return array_values((new \ReflectionClass(self::class))->getConstants());
        } catch (\Throwable) {
            return [];
        }
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::all(), true);
    }
}
