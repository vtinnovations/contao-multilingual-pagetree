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
 * The outbound transport: native cURL, locked to the one permitted host.
 *
 * The destination check happens here rather than only at the call site, so a
 * future bug elsewhere cannot send traffic to another host. Redirects are
 * refused instead of followed, the protocol is restricted to HTTPS for both the
 * request and any redirect target, TLS peer and hostname verification stay on,
 * and the response is aborted as soon as it exceeds the cap.
 */
final class CurlChannelTransport implements ChannelTransportInterface
{
    public function postJson(string $url, string $body, int $connectTimeout, int $totalTimeout, int $maxBytes): ChannelResponse
    {
        $handle = $this->open($url);

        $received = '';
        $overflow = false;

        $this->configure($handle, $url, $body, $connectTimeout, $totalTimeout);

        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($resource, string $chunk) use (&$received, &$overflow, $maxBytes): int {
            if (strlen($received) + strlen($chunk) > $maxBytes) {
                $overflow = true;

                // Returning a short count aborts the transfer immediately.
                return 0;
            }

            $received .= $chunk;

            return strlen($chunk);
        });

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string) (curl_getinfo($handle, CURLINFO_CONTENT_TYPE) ?: '');
        $errorNumber = curl_errno($handle);
        curl_close($handle);

        if ($overflow) {
            throw new ChannelTransportException(ChannelTransportException::RESPONSE_TOO_LARGE);
        }

        if (false === $ok) {
            $category = match (true) {
                in_array($errorNumber, [CURLE_OPERATION_TIMEDOUT], true) => ChannelTransportException::TRANSPORT_TIMEOUT,
                in_array($errorNumber, [CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER], true) => ChannelTransportException::TLS_FAILURE,
                default => ChannelTransportException::VERIFICATION_UNAVAILABLE,
            };

            throw new ChannelTransportException($category);
        }

        return new ChannelResponse($status, $contentType, $received);
    }

    public function postJsonWithoutResponse(string $url, string $body, int $connectTimeout, int $totalTimeout): void
    {
        $handle = $this->open($url);

        $this->configure($handle, $url, $body, $connectTimeout, $totalTimeout);

        // The body is read and discarded: nothing from the response is parsed,
        // stored, logged or shown.
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static fn ($resource, string $chunk): int => strlen($chunk));

        curl_exec($handle);
        curl_close($handle);
    }

    /**
     * @return \CurlHandle
     */
    private function open(string $url)
    {
        if (!in_array($url, [ChannelAddress::verify(), ChannelAddress::signal()], true)) {
            throw new ChannelTransportException(ChannelTransportException::INTERNAL_ERROR);
        }

        if (!function_exists('curl_init')) {
            throw new ChannelTransportException(ChannelTransportException::CURL_EXTENSION_MISSING);
        }

        $handle = curl_init();

        if (false === $handle) {
            throw new ChannelTransportException(ChannelTransportException::INTERNAL_ERROR);
        }

        return $handle;
    }

    /**
     * @param \CurlHandle $handle
     */
    private function configure($handle, string $url, string $body, int $connectTimeout, int $totalTimeout): void
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $totalTimeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Expect:',
            ],
            CURLOPT_USERAGENT => ProductProfile::PROJECT_SLUG.'/channel',
        ];

        // CURLOPT_PROTOCOLS is deprecated from cURL 7.85; the string variant is
        // used where available, so no downgrade to http:// is ever possible.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);
    }
}
