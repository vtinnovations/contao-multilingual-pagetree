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
 * The only outbound transport this bundle may use for channel traffic.
 *
 * Implementations must pin the destination to {@see ChannelAddress::prefix()},
 * keep TLS peer and hostname verification on, refuse redirects and cap both time
 * and response size. Tests substitute this port instead of contacting anything.
 */
interface ChannelTransportInterface
{
    /**
     * @throws ChannelTransportException
     */
    public function postJson(string $url, string $body, int $connectTimeout, int $totalTimeout, int $maxBytes): ChannelResponse;

    /**
     * Fire-and-forget POST used for the usage signal: no response processing, no
     * output, and any failure is swallowed by the caller.
     *
     * @throws ChannelTransportException
     */
    public function postJsonWithoutResponse(string $url, string $body, int $connectTimeout, int $totalTimeout): void;
}
