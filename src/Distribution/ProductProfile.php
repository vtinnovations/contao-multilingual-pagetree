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
 * The frozen identity and limits this product uses on the distribution channel.
 *
 * Every value here is part of the wire contract. None of it may be made
 * configurable: an installation that could choose its own identity or its own
 * size limits could be pointed at, or fed by, something other than the real
 * distribution service.
 *
 * Network destinations deliberately do not live here; they are assembled by
 * {@see ChannelAddress}, and the verification material lives in a third place
 * again, so no single file describes the whole trust setup.
 *
 * @internal this class is not a supported extension point
 */
final class ProductProfile
{
    /** Registered display name; sent verbatim in every packet. */
    public const PROJECT = 'Contao Multilingual Pagetree';

    /** Immutable, route-safe project slug. */
    public const PROJECT_SLUG = 'contao-multilingual-pagetree';

    /** Catalogue identifier of this product. */
    public const PRODUCT_ID = 'vt-contao-multilingual-pagetree';

    /** The only document schema version this release understands. */
    public const SCHEMA_VERSION = 2;

    /**
     * The model this product is issued under.
     *
     * One lifetime entitlement, free of charge, granting every feature. It is
     * recorded here because the issuing side has to agree with it, but it is not
     * a switch: the accepted package vocabulary lives in {@see ServiceTier} and
     * the term rule is enforced where entitlement is decided, so changing this
     * string alone changes nothing.
     */
    public const LICENCE_MODEL = 'lifetime_free';

    /** Exact public path of the server-initiated update endpoint. */
    public const ENDPOINT_PATH = '/rest/api/v1/'.self::PROJECT_SLUG.'-license-updater';

    /** Largest accepted response body of an outbound call, in bytes. */
    public const MAX_RESPONSE_BYTES = 65536;

    /** Largest accepted inbound request body, in bytes. */
    public const MAX_REQUEST_BYTES = 65536;

    /** Maximum accepted issued-key bytes before JSON framing. */
    public const MAX_LICENSE_KEY_BYTES = 4096;

    /** Accepted age of an inbound request timestamp, in seconds. */
    public const REQUEST_PAST_TOLERANCE = 300;

    /** Accepted clock lead of an inbound request timestamp, in seconds. */
    public const REQUEST_FUTURE_TOLERANCE = 60;

    /** Accepted difference between our clock and the reported server time. */
    public const SERVER_TIME_TOLERANCE = 900;

    /** How long processed-request records are kept, in seconds. */
    public const LEDGER_RETENTION = 604800;

    /** Connect timeout for outbound channel calls, in seconds. */
    public const CONNECT_TIMEOUT = 5;

    /** Total timeout for outbound channel calls, in seconds. */
    public const TOTAL_TIMEOUT = 15;

    /** Connect timeout for the fire-and-forget usage signal, in seconds. */
    public const SIGNAL_CONNECT_TIMEOUT = 2;

    /** Total timeout for the fire-and-forget usage signal, in seconds. */
    public const SIGNAL_TOTAL_TIMEOUT = 4;

    /** Seconds an installation waits for the cluster lock before giving up. */
    public const LOCK_TIMEOUT = 10;

    private function __construct()
    {
    }
}
