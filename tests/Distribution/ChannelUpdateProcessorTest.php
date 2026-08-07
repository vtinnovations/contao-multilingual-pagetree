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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Distribution;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelUpdateProcessor;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\PackageActivator;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Security\ChannelRequestVerifier;
use Vtinnovations\ContaoMultilingualPagetree\Storage\LedgerEntry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FrozenClock;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryRequestLedger;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

/**
 * The inbound update path: authenticate, reserve, verify, apply.
 */
final class ChannelUpdateProcessorTest extends TestCase
{
    private const NOW = PackageFactory::NOW;
    private const PATH = ProductProfile::ENDPOINT_PATH;

    private PackageFactory $factory;
    private InMemoryPackageStore $store;
    private InMemoryRequestLedger $ledger;
    private ChannelUpdateProcessor $processor;

    protected function setUp(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('The sodium extension is not available.');
        }

        $this->factory = new PackageFactory();
        $this->store = new InMemoryPackageStore();
        $this->ledger = new InMemoryRequestLedger();

        $hosts = new CanonicalHost();
        $this->processor = new ChannelUpdateProcessor(
            new ChannelRequestVerifier($this->factory->signatures(), $hosts),
            $this->factory->reader(),
            new PackageActivator($this->store, $this->ledger, $hosts),
            $this->ledger,
            $this->factory->identity($this->store),
            new FrozenClock(self::NOW),
        );
    }

    public function testAValidRequestIsApplied(): void
    {
        $result = $this->send($this->body());

        self::assertSame(200, $result->httpStatus);
        self::assertSame('updated', $result->status);
        self::assertSame(7, $result->version);
        self::assertNotNull($this->store->package);
        self::assertSame(1, $this->store->writes);
        self::assertSame(PackageFactory::HOST, $this->store->host);
    }

    public function testAnExactRetryIsIdempotent(): void
    {
        $body = $this->body();

        self::assertSame('updated', $this->send($body)->status);

        $repeat = $this->send($body);

        self::assertSame(200, $repeat->httpStatus);
        self::assertSame('already_processed', $repeat->status);
        self::assertSame(7, $repeat->version);
        self::assertSame(1, $this->store->writes, 'The package must not be written twice.');
    }

    public function testTheSameRequestIdWithOtherContentIsRefused(): void
    {
        self::assertSame('updated', $this->send($this->body())->status);

        $conflicting = $this->body(['nonce' => 'nonce-000000000002'], ['license_version' => 9]);
        $result = $this->send($conflicting, 'req-00000001', 'nonce-000000000002');

        self::assertSame(409, $result->httpStatus);
        self::assertSame('rejected', $result->status);
        self::assertSame(7, $this->store->package?->document->version);
    }

    public function testASpentNonceCannotBeReusedByAnotherRequest(): void
    {
        self::assertSame('updated', $this->send($this->body())->status);

        $replay = $this->body(['request_id' => 'req-00000002'], ['license_version' => 8]);
        $result = $this->send($replay, 'req-00000002');

        self::assertSame(401, $result->httpStatus);
        self::assertSame(7, $this->store->package?->document->version);
    }

    public function testANewerVersionReplacesTheActiveOne(): void
    {
        $this->send($this->body());

        $result = $this->send(
            $this->body(['request_id' => 'req-00000002', 'nonce' => 'nonce-000000000002'], ['license_version' => 9]),
            'req-00000002',
            'nonce-000000000002',
        );

        self::assertSame('updated', $result->status);
        self::assertSame(9, $this->store->package?->document->version);
    }

    public function testAnOlderVersionCannotRollTheInstallationBack(): void
    {
        $this->send($this->body([], ['license_version' => 9]));

        $result = $this->send(
            $this->body(['request_id' => 'req-00000002', 'nonce' => 'nonce-000000000002'], ['license_version' => 8]),
            'req-00000002',
            'nonce-000000000002',
        );

        self::assertSame(409, $result->httpStatus);
        self::assertSame(9, $this->store->package?->document->version, 'A signed older package must never win.');
    }

    public function testARevocationIsAppliedBecauseItCarriesANewerVersion(): void
    {
        $this->send($this->body());

        $result = $this->send(
            $this->body(
                ['request_id' => 'req-00000002', 'nonce' => 'nonce-000000000002'],
                ['license_version' => 8, 'validation_status' => 'revoked'],
            ),
            'req-00000002',
            'nonce-000000000002',
        );

        self::assertSame('updated', $result->status);
        self::assertSame('revoked', $this->store->package?->document->status->value);
    }

    public function testAPackageForAnotherHostIsRefused(): void
    {
        $result = $this->send($this->body(['domain' => 'www.example.com'], ['license_domain' => 'www.example.com']));

        self::assertSame(403, $result->httpStatus);
        self::assertNull($this->store->package);
    }

    public function testAPacketDomainThatDisagreesWithTheSignedHostIsRefused(): void
    {
        $result = $this->send($this->body(['domain' => 'shop.example.com']));

        self::assertSame(403, $result->httpStatus);
        self::assertNull($this->store->package);
    }

    /**
     * A push whose document predates the signed host set is refused.
     *
     * It is correctly signed, so the only thing that stops it is the rule
     * itself: accepting it would let a replayed pre-upgrade document overwrite
     * current state with a binding this installation would have to guess at.
     */
    public function testAPushedPackageWithoutTheSignedHostSetIsRefused(): void
    {
        $this->send($this->body());

        $package = $this->factory->legacyWirePackage(['license_version' => 9]);
        $result = $this->send(
            $this->body([
                'request_id' => 'req-00000002',
                'nonce' => 'nonce-000000000002',
                'license_payload_b64' => $package['payload'],
                'integrity' => $package['integrity'],
            ]),
            'req-00000002',
            'nonce-000000000002',
        );

        self::assertSame(401, $result->httpStatus);
        self::assertSame(7, $this->store->package?->document->version, 'Working state must survive.');
        self::assertSame(LedgerEntry::RESULT_FAILED, $this->ledger->entries['req-00000002']->result);
    }

    public function testAnInvalidPackageIsRefusedAndRecorded(): void
    {
        $package = $this->factory->wirePackage();
        $body = $this->body(['license_payload_b64' => base64_encode($package['bytes'].' ')]);

        $result = $this->send($body);

        self::assertSame(401, $result->httpStatus);
        self::assertNull($this->store->package);
        self::assertSame(LedgerEntry::RESULT_FAILED, $this->ledger->entries['req-00000001']->result);
    }

    public function testAnUnavailableLedgerRefusesInsteadOfProcessingUnprotected(): void
    {
        $this->ledger->unavailable = true;

        $result = $this->send($this->body());

        self::assertSame(503, $result->httpStatus);
        self::assertNull($this->store->package);
    }

    public function testAStorageFailureKeepsThePreviousState(): void
    {
        $this->send($this->body());
        $this->store->failWrites = true;

        $result = $this->send(
            $this->body(['request_id' => 'req-00000002', 'nonce' => 'nonce-000000000002'], ['license_version' => 9]),
            'req-00000002',
            'nonce-000000000002',
        );

        self::assertSame(503, $result->httpStatus);
        self::assertSame(7, $this->store->package?->document->version);
    }

    public function testAFailedAttemptCanBeRetriedWithTheSameRequestId(): void
    {
        $this->ledger->lockHeld = true;
        $body = $this->body();

        self::assertSame(503, $this->send($body)->httpStatus);

        $this->ledger->lockHeld = false;
        $this->ledger->entries['req-00000001'] = new LedgerEntry('req-00000001', hash('sha256', $body), LedgerEntry::RESULT_FAILED, null, self::NOW, self::NOW);

        self::assertSame('updated', $this->send($body)->status);
    }

    /**
     * @param array<string, mixed> $bodyOverrides
     * @param array<string, mixed> $documentOverrides
     */
    private function body(array $bodyOverrides = [], array $documentOverrides = []): string
    {
        return json_encode(
            $this->factory->updateBody($bodyOverrides, $documentOverrides),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    private function send(string $body, string $requestId = 'req-00000001', string $nonce = 'nonce-000000000001'): \Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelUpdateResult
    {
        return $this->processor->process(
            'POST',
            self::PATH,
            $this->factory->requestHeaders('POST', self::PATH, $body, self::NOW, $requestId, $nonce),
            $body,
        );
    }
}
