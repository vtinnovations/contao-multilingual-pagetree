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

namespace Vtinnovations\ContaoMultilingualPagetree\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelUpdateProcessor;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Security\ChannelRequestVerifier;

/**
 * The public edge of the server-to-server update path.
 *
 * It is deliberately thin: it enforces the request *shape* - path, method, media
 * type and size - and hands everything else to the processor. No verification
 * logic, no key material, no storage and no entitlement decision lives here, and
 * nothing in this class can write a file or choose a path.
 *
 * The route is intentionally not behind the Contao backend login and not behind
 * a browser CSRF token: the caller is another server, which has neither. Trust
 * comes exclusively from the signed request the processor verifies.
 */
final class ChannelUpdateController
{
    /** Media type the protocol defines for this endpoint. */
    private const MEDIA_TYPE = 'application/json';

    public function __construct(private readonly ChannelUpdateProcessor $processor)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // The exact path is part of what the caller signs, so a request that
        // arrived somewhere else is not this endpoint.
        if (ProductProfile::ENDPOINT_PATH !== $request->getPathInfo()) {
            return $this->respond(Response::HTTP_NOT_FOUND, ['status' => 'not_found']);
        }

        if ('POST' !== strtoupper($request->getMethod())) {
            // Explicitly 405 with an Allow header, never a 404: the endpoint
            // exists, this verb simply does not.
            return $this->respond(Response::HTTP_METHOD_NOT_ALLOWED, ['status' => 'method_not_allowed'], ['Allow' => 'POST']);
        }

        if (self::MEDIA_TYPE !== strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''), 2)[0]))) {
            return $this->respond(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, ['status' => 'unsupported_media_type']);
        }

        $declared = $request->headers->get('Content-Length');

        if (is_string($declared) && 1 === preg_match('/^\d+$/', $declared) && (int) $declared > ProductProfile::MAX_REQUEST_BYTES) {
            return $this->respond(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, ['status' => 'payload_too_large']);
        }

        $body = $request->getContent();

        if (!is_string($body) || strlen($body) > ProductProfile::MAX_REQUEST_BYTES) {
            return $this->respond(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, ['status' => 'payload_too_large']);
        }

        $result = $this->processor->process(
            $request->getMethod(),
            ProductProfile::ENDPOINT_PATH,
            [
                ChannelRequestVerifier::HEADER_REQUEST_ID => $request->headers->get(ChannelRequestVerifier::HEADER_REQUEST_ID),
                ChannelRequestVerifier::HEADER_TIMESTAMP => $request->headers->get(ChannelRequestVerifier::HEADER_TIMESTAMP),
                ChannelRequestVerifier::HEADER_NONCE => $request->headers->get(ChannelRequestVerifier::HEADER_NONCE),
                ChannelRequestVerifier::HEADER_KEY_ID => $request->headers->get(ChannelRequestVerifier::HEADER_KEY_ID),
                ChannelRequestVerifier::HEADER_SIGNATURE => $request->headers->get(ChannelRequestVerifier::HEADER_SIGNATURE),
            ],
            $body,
        );

        return $this->respond($result->httpStatus, $result->toPayload());
    }

    /**
     * @param array<string, string|int> $payload
     * @param array<string, string>     $headers
     */
    private function respond(int $status, array $payload, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($payload, $status, $headers);

        // Never cached anywhere: not by Contao, not by a reverse proxy, not by
        // a CDN sitting in front of the installation.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
