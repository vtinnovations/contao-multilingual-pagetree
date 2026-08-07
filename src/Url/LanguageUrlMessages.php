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

namespace Vtinnovations\ContaoMultilingualPagetree\Url;

/**
 * Validation messages of the language URL fields.
 *
 * The translated text comes from the normal Contao language files; the English
 * literals below are the last resort for a context in which no language file is
 * loaded - a migration, a console command or a unit test.
 */
final class LanguageUrlMessages
{
    public const GROUP = 'contaoMultilingualPagetreeUrl';

    /** @var array<string, string> */
    private const FALLBACKS = [
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

    public static function text(string $key): string
    {
        $translated = $GLOBALS['TL_LANG']['MSC'][self::GROUP][$key] ?? null;

        if (is_string($translated) && '' !== $translated) {
            return $translated;
        }

        return self::FALLBACKS[$key] ?? $key;
    }

    /**
     * @return array<string, string>
     */
    public static function keys(): array
    {
        return self::FALLBACKS;
    }
}
