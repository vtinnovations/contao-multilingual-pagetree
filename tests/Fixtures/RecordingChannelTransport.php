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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelResponse;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelTransportException;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelTransportInterface;

/**
 * A transport that records what would have been sent and replays a prepared
 * answer. No test ever contacts a real service.
 */
final class RecordingChannelTransport implements ChannelTransportInterface
{
    /** @var list<array{url: string, body: string, connect: int, total: int}> */
    public array $calls = [];

    /** @var list<array{url: string, body: string}> */
    public array $signals = [];

    /** Every attempt, including the ones {@see self::$failSignals} rejects. */
    public int $signalAttempts = 0;

    /** Makes the fire-and-forget path throw, without affecting `postJson`. */
    public bool $failSignals = false;

    public ?ChannelResponse $response = null;
    public ?\Throwable $failure = null;

    /**
     * Receives the decoded outgoing packet and returns the answer, so a test can
     * echo back the request id the client generated internally.
     *
     * @var null|callable(array<string, mixed>): ChannelResponse
     */
    public $responder = null;

    public function postJson(string $url, string $body, int $connectTimeout, int $totalTimeout, int $maxBytes): ChannelResponse
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'connect' => $connectTimeout, 'total' => $totalTimeout];

        if (null !== $this->failure) {
            throw $this->failure;
        }

        if (null !== $this->responder) {
            $decoded = json_decode($body, true);

            return ($this->responder)(is_array($decoded) ? $decoded : []);
        }

        return $this->response ?? new ChannelResponse(200, 'application/json', '{}');
    }

    public function postJsonWithoutResponse(string $url, string $body, int $connectTimeout, int $totalTimeout): void
    {
        ++$this->signalAttempts;

        if ($this->failSignals) {
            throw new ChannelTransportException(ChannelTransportException::TRANSPORT_TIMEOUT);
        }

        $this->signals[] = ['url' => $url, 'body' => $body];

        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    /** @return array<string, mixed> */
    public function lastPacket(): array
    {
        $last = $this->calls[array_key_last($this->calls)] ?? null;

        if (null === $last) {
            return [];
        }

        $decoded = json_decode($last['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    public function failWith(string $message): void
    {
        $this->failure = new ChannelTransportException(ChannelTransportException::VERIFICATION_UNAVAILABLE, $message);
    }
}
