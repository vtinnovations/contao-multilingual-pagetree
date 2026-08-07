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

namespace Vtinnovations\ContaoMultilingualPagetree\Distribution;

/**
 * The fixed outbound destinations of this bundle.
 *
 * There are exactly two, both on one host, both HTTPS. They are code constants,
 * never configuration: neither a setting, nor request data, nor a DNS alias, nor
 * a response may select a different destination. The pieces are kept apart and
 * concatenated at call time so a release build can transform the fragments
 * without changing a single call site; the assembly is plain concatenation and
 * never evaluates data.
 *
 * {@see self::prefix()} is asserted by the transport itself, independently of
 * its caller, so a future bug in a caller cannot redirect traffic elsewhere.
 *
 * @internal this class is not a supported extension point
 */
final class ChannelAddress
{
    private const SCHEME = 'https://';
    private const HOST_LEFT = 'www.v-t';
    private const HOST_RIGHT = '.one';
    private const VERIFY_PATH = '/api/v1/verify';
    private const SIGNAL_PATH = '/rest/api/v1/log-envoke';

    private function __construct()
    {
    }

    /** The only host this bundle ever contacts. */
    public static function host(): string
    {
        return self::HOST_LEFT.self::HOST_RIGHT;
    }

    /** Activation and administrator refresh endpoint. */
    public static function verify(): string
    {
        return self::SCHEME.self::host().self::VERIFY_PATH;
    }

    /** Usage signal endpoint. */
    public static function signal(): string
    {
        return self::SCHEME.self::host().self::SIGNAL_PATH;
    }

    /** The prefix every permitted outbound URL of this bundle must start with. */
    public static function prefix(): string
    {
        return self::SCHEME.self::host().'/';
    }
}
