<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetree'] = 'Contao Multilingual Pagetree';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFieldState'] = ['Translation state', 'Inherited fields automatically follow future source-language changes.'];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFieldStates'] = [
    'inherit' => 'Inherit from source',
    'custom' => 'Use custom translation',
    'empty' => 'Leave deliberately empty',
];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeSourcePreview'] = 'Current source value';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFieldStatesMigration'] = 'Initialise Contao Multilingual Pagetree field states';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFieldStatesMigrated'] = 'Initialised field states for %d translation records.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeAvailabilityMigration'] = 'Initialise Contao Multilingual Pagetree page availability modes';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeAvailabilityMigrated'] = 'Initialised the page availability mode of %d language configurations.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeSwitcherDisplayMigration'] = 'Initialise Contao Multilingual Pagetree switcher display settings';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeSwitcherDisplayMigrated'] = 'Initialised the unavailable-language display of %d switcher modules.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeSwitcherLabel'] = 'Language switcher';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeCurrentLanguage'] = 'Current language';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeUnavailableLanguage'] = 'Not available in this language';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeTranslationLegend'] = 'Translatable content';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewStatus'] = ['Review status', 'Editorial review state of this translation.'];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewStatuses'] = [
    'unreviewed' => 'Not yet reviewed',
    'up_to_date' => 'Up to date',
    'needs_review' => 'Needs review',
    'source_missing' => 'Source record unavailable',
];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeMarkReviewed'] = ['Mark translation as reviewed', 'Record the current source state as reviewed.'];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewMarkReviewed'] = 'Mark translation as reviewed';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewReviewedAt'] = 'Reviewed on';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewReviewedBy'] = 'Reviewed by';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewChangedFields'] = 'Changed source fields';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewReviewedValue'] = 'Reviewed source value';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewCurrentValue'] = 'Current source value';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewSourceMissing'] = 'The connected source record is unavailable, so this translation cannot be reviewed.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewDone'] = 'The translation was marked as reviewed.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewFailed'] = 'The translation could not be marked as reviewed.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewDenied'] = 'You are not allowed to review this translation.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewInvalidToken'] = 'Invalid request token for the review action.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewMigration'] = 'Initialise Contao Multilingual Pagetree review states';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewMigrated'] = 'Initialised the review state of %d translation records.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeContentModeMigration'] = 'Initialise Contao Multilingual Pagetree content translation modes';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeContentModeMigrated'] = 'Initialised the content translation mode of %d language configurations.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeContentModeDenied'] = 'You are not allowed to change the content translation mode.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeContentModeInvalidToken'] = 'Invalid request token for the content mode change.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeContentModeConfirm'] = 'Activating "%s" for "%s" keeps %d connected translation records and %d free records stored, but %d of them will no longer render. No data is deleted. Confirm the change to continue.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFreeRecord'] = 'Free language content';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrity'] = 'Multilingual integrity';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityScan'] = 'Scan current site';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityRescan'] = 'Rescan';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityPreview'] = 'Preview repairs';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityExecute'] = 'Execute selected repairs';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityDryRun'] = 'Dry run';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityDestructive'] = 'This action deletes records and cannot be undone automatically.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityQuarantine'] = 'Quarantined (retained but inactive)';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityRepairCompleted'] = 'The repair was completed.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityRepairFailed'] = 'The repair could not be completed.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityStalePlan'] = 'The data changed since the preview. Please rescan and try again.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityDenied'] = 'You are not allowed to repair multilingual records.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityInvalidToken'] = 'Invalid request token for the repair action.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityLanguageCleanup'] = 'Delete language and all related multilingual data';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityIndexMigration'] = 'Add Contao Multilingual Pagetree integrity indexes';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityIndexMigrated'] = 'Created %d integrity index(es).';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegritySeverities'] = [
    'info' => 'Information',
    'warning' => 'Warning',
    'error' => 'Error',
    'critical' => 'Critical',
];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityRepairability'] = [
    'none' => 'No repair available',
    'automatic' => 'Repaired automatically',
    'confirmation' => 'Repair requires confirmation',
    'manual' => 'Manual decision required',
];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeIntegrityIssues'] = [
    'invalid_language_configuration' => 'Invalid language configuration',
    'duplicate_language_configuration' => 'Duplicate language configuration',
    'multiple_fallback_languages' => 'Multiple default languages',
    'missing_fallback_language' => 'No default language configured',
    'invalid_root_relation' => 'Invalid root page relation',
    'missing_source' => 'Missing source record',
    'self_referential_source' => 'Translation references itself',
    'translation_source_relation' => 'Translation references another translation',
    'cross_site_relation' => 'Relation crosses a site boundary',
    'cross_language_relation' => 'Relation crosses a language boundary',
    'duplicate_translation' => 'Duplicate translation',
    'orphaned_connected_translation' => 'Orphaned connected translation',
    'orphaned_free_content' => 'Orphaned free content',
    'invalid_free_parent' => 'Invalid free content parent',
    'free_content_cycle' => 'Free content relation cycle',
    'invalid_field_states' => 'Invalid field states',
    'invalid_review_metadata' => 'Invalid review metadata',
    'invalid_alias' => 'Invalid alias',
    'duplicate_alias' => 'Duplicate alias',
    'invalid_publication_range' => 'Invalid publication period',
    'inactive_connected_data' => 'Inactive connected data (retained)',
    'inactive_free_data' => 'Inactive free content (retained)',
    'rule_failure' => 'An integrity rule failed',
];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeActionMethodNotAllowed'] = 'This action must be submitted, not opened as a link.';
$GLOBALS['TL_LANG']['MSC']['inlineLangSwitch'] = 'Switch language';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeBackend'] = [
    'source' => 'Default/source language',
    'count' => '%d additional language(s) configured.',
    'empty' => 'No additional languages configured.',
    'manage' => 'Manage additional languages',
        'denied' => 'You are not allowed to manage languages for this site root.',
    'licenceRequired' => 'A valid license is required before additional languages can be managed.',
    'rootRequired' => 'The language configuration must belong to a site root.',
    'invalid' => 'Enter a valid locale or language code.',
    'invalidFlag' => 'Select a valid flag.',
    'sourceDuplicate' => 'The source language cannot be added as a target language.',
    'duplicate' => 'This target language already exists for the site root.',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'target' => 'Target language',
];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeLicence'] = [
    'notice' => 'A valid license is required.',
    'obtain' => 'Obtain a licence from',
    'status' => 'Licence status',
    'domain' => 'Licence domain',
    'term' => 'Licence term',
    'lifetime' => 'Lifetime',
    'notLifetime' => 'Not active',
    'key' => 'Licence key',
    'activate' => 'Activate licence',
    'replace' => 'Replace licence',
    'refresh' => 'Refresh or verify licence',
    'remove' => 'Remove licence',
    'removeConfirm' => 'Remove the stored licence? Existing multilingual data remains untouched.',
    'statuses' => [
        'granted' => 'Active', 'not_activated' => 'Not activated',
        'state_unusable' => 'Invalid licence', 'host_mismatch' => 'Wrong domain', 'host_unknown' => 'Domain unavailable',
        'not_yet_valid' => 'Not yet valid', 'expired' => 'Expired', 'status_not_valid' => 'Invalid licence',
        'verification_unavailable' => 'Verification unavailable',
    ],
    'messages' => [
        'status' => 'Licence status refreshed.', 'applied' => 'Licence activated.', 'unchanged' => 'Licence is already current.',
        'denied' => 'Invalid licence.', 'unreachable' => 'Verification unavailable.', 'malformed_response' => 'Invalid verification response.',
        'host_unknown' => 'The current domain could not be determined.', 'not_activated' => 'No licence is activated.',
        'verification_unavailable' => 'Verification unavailable.', 'permission_denied' => 'You are not allowed to manage licences.',
        'key_required' => 'Enter a licence key.', 'removed' => 'The licence was removed. Multilingual data was not changed.',
    ],
];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeRootLicence'] = [
    'legend' => 'Contao Multilingual Pagetree Licence management',
    'notice' => 'A valid license is required.',
    'obtain' => 'Obtain a licence from',
    'status' => 'Licence status', 'rootDomain' => 'Root-page domain', 'domain' => 'Licence domain',
    'term' => 'Licence term', 'lifetime' => 'Lifetime', 'notActivated' => 'Not activated',
    'activationState' => 'Activation state', 'active' => 'Active', 'inactive' => 'Not active',
    'missingDomain' => 'Missing domain', 'missingDomainHelp' => 'Configure the root-page domain before activation.',
    'legacyNotice' => 'Legacy installation-wide licence data was not adopted because it could not be assigned unambiguously. Activate this root page explicitly; the legacy files remain untouched.',
    'readOnly' => 'You may view this status but are not allowed to manage this root page’s licence.',
    'key' => 'Licence key', 'activate' => 'Activate licence', 'replace' => 'Replace licence',
    'refresh' => 'Refresh licence', 'verify' => 'Verify licence', 'remove' => 'Remove licence',
    'removeConfirm' => 'Confirm licence removal? Existing multilingual data remains untouched.',
    'statuses' => [
        'granted' => 'Active', 'not_activated' => 'Not activated',
        'state_unusable' => 'Invalid licence', 'host_mismatch' => 'Wrong domain', 'host_unknown' => 'Missing domain',
        'wrong_project' => 'Wrong project', 'wrong_package' => 'Wrong package', 'signature_invalid' => 'Signature invalid',
        'tampered' => 'Licence tampered', 'not_yet_valid' => 'Not yet valid', 'expired' => 'Expired',
        'status_not_valid' => 'Invalid licence', 'verification_unavailable' => 'Verification unavailable',
        'refresh_required' => 'Refresh required', 'term_not_supported' => 'Unsupported licence term',
    ],
    'messages' => [
        'applied' => 'Licence activated.', 'unchanged' => 'Licence refreshed.', 'already_current' => 'Licence refreshed.',
        'removed' => 'Licence removed.', 'keyRequired' => 'Enter a licence key.',
        'permissionDenied' => 'You are not authorised to manage the licence for this website root.',
        'verificationUnavailable' => 'Verification unavailable.', 'verification_unavailable' => 'Verification unavailable.',
        'verified' => 'The stored licence is intact and valid for this website root.',
        'already_activated' => 'This website root already has a licence. Use “Replace licence” to change the key.',
        'wrongDomain' => 'The licence does not match this root page’s exact domain.', 'invalid' => 'Invalid licence.',
        'missing_key' => 'Enter a licence key.', 'invalid_key' => 'The licence key is invalid.',
        'wrong_domain' => 'The licence does not match this root page’s exact domain.',
        'wrong_project' => 'The licence belongs to another project.', 'wrong_package' => 'This licence is not the lifetime licence this product requires.',
        'not_yet_valid' => 'The licence is not valid yet.', 'expired' => 'The licence has expired.',
        'malformed_response' => 'The licence service returned a malformed response.',
        'unsupported_schema' => 'The licence response uses an unsupported protocol version.',
        'signature_invalid' => 'The licence response signature is invalid.',
        'signing_key_store_empty' => 'This build has no verification material, so no licence can be accepted.',
        'unknown_signing_key' => 'Licence response received, but the signing key is not available in this build.',
        'curl_extension_missing' => 'The PHP cURL extension required for licence verification is not available.',
        'transport_timeout' => 'Licence verification timed out.', 'tls_failure' => 'The secure connection for licence verification failed.',
        'response_too_large' => 'The licence service response exceeded the safe size limit.',
        'unexpected_content_type' => 'The licence service did not return a JSON response.',
        'storage_failure' => 'The verified licence could not be stored safely.', 'internal_error' => 'Licence verification failed internally.',
        'host_unknown' => 'Configure the root-page domain before activation.',
        'not_activated' => 'No licence is stored for this website root yet.',
        'state_unusable' => 'The stored licence could not be verified.',
        'refresh_required' => 'The stored licence predates the current licence format. Use “Refresh licence” once to update it; the stored licence stays untouched in the meantime.',
        'host_mismatch' => 'The licence does not match this root page’s exact domain.',
        'status_not_valid' => 'The stored licence is not valid.',
        'reference' => 'Reference: %s',
    ],
];

// Language URL mapping: migration labels and the validation messages of the
// central collision rules.
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeLanguageUrlMigration'] = 'Normalise Contao Multilingual Pagetree language URL settings';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeLanguageUrlMigrated'] = 'Normalised the language URL settings of %d language configurations.';
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeUrl'] = [
    'domainInvalid' => 'Please enter a valid hostname, for example www.example.de.',
    'domainScheme' => 'Please enter only a hostname without a protocol, for example www.example.de.',
    'domainPath' => 'Please enter only a hostname without a path.',
    'domainQuery' => 'Please enter only a hostname without a query string.',
    'domainFragment' => 'Please enter only a hostname without a fragment.',
    'domainPort' => 'Please enter only a hostname without a port.',
    'entryPointInvalid' => 'Please enter a valid entry point, for example /de.',
    'entryPointUrl' => 'Please enter only a path, not a complete URL.',
    'entryPointHost' => 'Please enter only a path, not a hostname.',
    'entryPointQuery' => 'Please enter an entry point without a query string.',
    'entryPointFragment' => 'Please enter an entry point without a fragment.',
    'entryPointTraversal' => 'The entry point must not contain "." or ".." segments.',
    'entryPointSlashes' => 'The entry point must not contain repeated slashes.',
    'entryPointControl' => 'The entry point contains invalid characters.',
    'duplicateMapping' => 'Another language of this website root already uses this domain and entry point.',
    'duplicateRootMapping' => 'Another language of this website root already uses the domain root of this hostname.',
    'protocolAmbiguity' => 'Two languages must not differ only by protocol while sharing the same hostname and entry point.',
    'crossRootConflict' => 'This hostname already belongs to another website root, so incoming requests could not be resolved deterministically.',
    'ambiguousEntryPoint' => 'This entry point cannot be resolved deterministically against the other languages of this website root.',
    'unknownRoot' => 'The language record does not belong to a Contao website root.',
];

// Why the backend fell back to the source language. Categories only: never a
// record value, a token or any licence detail.
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeLanguageRefused'] = [
    'default' => 'This language cannot be edited here.',
    'invalid_parameter' => 'The requested language is not a valid language code, so the source language is shown.',
    'unknown_root' => 'This record could not be assigned to a website root, so the source language is shown.',
    'is_default_language' => 'The requested language is the source language of this website root.',
    'not_configured' => 'This language is not configured for this website root.',
    'not_published' => 'This language is not published for this website root, so it cannot be edited.',
    'foreign_root' => 'This language belongs to another website root and cannot be edited here.',
    'permission_denied' => 'You are not allowed to edit the languages of this website root.',
    'licence_denied' => 'A valid license is required before translations can be edited.',
    'root_domain_missing' => 'Configure the domain of this website root before editing translations.',
];
$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeContentSaveFailed'] = 'The translation could not be saved. The source language was not changed.';
