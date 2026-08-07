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
 * An outbound channel call could not be completed.
 *
 * Always treated as transient by the caller: a network problem, a TLS problem or
 * a service outage never invalidates working local state.
 */
final class ChannelTransportException extends \RuntimeException
{
    public const CURL_EXTENSION_MISSING = 'curl_extension_missing';
    public const TRANSPORT_TIMEOUT = 'transport_timeout';
    public const TLS_FAILURE = 'tls_failure';
    public const RESPONSE_TOO_LARGE = 'response_too_large';
    public const VERIFICATION_UNAVAILABLE = 'verification_unavailable';
    public const INTERNAL_ERROR = 'internal_error';

    public function __construct(public readonly string $category, string $message = 'Channel transport failure.')
    {
        parent::__construct($message);
    }
}
