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
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelResponse;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\PackageActivator;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\RegistrationClient;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\RegistrationOutcome;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Support\DetachedSignature;
use Vtinnovations\ContaoMultilingualPagetree\Support\KeyDirectory;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\CapturingLogger;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FrozenClock;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryRequestLedger;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\RecordingChannelTransport;

/**
 * Activation and refresh. Every failure mode must leave working state alone.
 */
final class RegistrationClientTest extends TestCase
{
    private const NOW = PackageFactory::NOW;

    private PackageFactory $factory;
    private RecordingChannelTransport $transport;
    private InMemoryPackageStore $store;
    private RegistrationClient $client;

    protected function setUp(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('The sodium extension is not available.');
        }

        $this->factory = new PackageFactory();
        $this->transport = new RecordingChannelTransport();
        $this->store = new InMemoryPackageStore();
        $this->client = $this->client();
    }

    public function testActivationSendsTheDocumentedPacketAndStoresTheAnswer(): void
    {
        $this->respondWith();

        $outcome = $this->client->activate('CMP-TEST-0000-0000');

        self::assertTrue($outcome->successful);
        self::assertSame(RegistrationOutcome::APPLIED, $outcome->status);
        self::assertSame(7, $outcome->version);

        $packet = $this->transport->lastPacket();

        self::assertSame('https://www.v-t.one/api/v1/verify', $this->transport->calls[0]['url']);
        self::assertSame('activate', $packet['action']);
        self::assertSame(ProductProfile::PROJECT, $packet['project']);
        self::assertSame(ProductProfile::PROJECT_SLUG, $packet['project_slug']);
        self::assertSame(ProductProfile::PRODUCT_ID, $packet['product_id']);
        self::assertSame('CMP-TEST-0000-0000', $packet['license_key']);
        self::assertSame(PackageFactory::HOST, $packet['domain']);
        self::assertSame(self::NOW, $packet['timestamp']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $packet['request_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', (string) $packet['nonce']);
        self::assertArrayNotHasKey('current_license_version', $packet);
        self::assertNotNull($this->store->package);
    }

    public function testRefreshSendsTheCurrentVersion(): void
    {
        $this->store->package = $this->factory->sealedPackage();
        $this->store->host = PackageFactory::HOST;
        $this->respondWith(['license_version' => 9]);

        $outcome = $this->client->refresh();

        self::assertTrue($outcome->successful);
        self::assertSame(9, $this->store->package->document->version);

        $packet = $this->transport->lastPacket();

        self::assertSame('refresh', $packet['action']);
        self::assertSame(7, $packet['current_license_version']);
    }

    public function testRefreshWithoutStoredStateDoesNotContactTheService(): void
    {
        $outcome = $this->client->refresh();

        self::assertSame(RegistrationOutcome::NOT_ACTIVATED, $outcome->status);
        self::assertSame([], $this->transport->calls);
    }

    public function testAnUnchangedRefreshReportsUnchangedAndWritesNothing(): void
    {
        $this->store->package = $this->factory->sealedPackage();
        $this->store->host = PackageFactory::HOST;
        $this->respondWith();

        $outcome = $this->client->refresh();

        self::assertTrue($outcome->successful);
        self::assertSame(RegistrationOutcome::UNCHANGED, $outcome->status);
        self::assertSame(0, $this->store->writes);
    }

    public function testAnOlderPackageNeverReplacesNewerState(): void
    {
        $this->store->package = $this->factory->sealedPackage(['license_version' => 9]);
        $this->store->host = PackageFactory::HOST;
        $this->respondWith(['license_version' => 8]);

        $outcome = $this->client->refresh();

        self::assertFalse($outcome->successful);
        self::assertSame(9, $this->store->package->document->version);
    }

    /**
     * @dataProvider transientFailures
     */
    public function testTransientFailuresPreserveTheStoredState(callable $arrange, string $expectedStatus): void
    {
        $this->store->package = $this->factory->sealedPackage();
        $this->store->host = PackageFactory::HOST;
        $arrange($this->transport);

        $outcome = $this->client->refresh();

        self::assertFalse($outcome->successful);
        self::assertSame($expectedStatus, $outcome->status);
        self::assertNotNull($this->store->package, 'A failed refresh must never erase working state.');
        self::assertSame(0, $this->store->writes);
    }

    /**
     * @return iterable<string, array{callable, string}>
     */
    public static function transientFailures(): iterable
    {
        yield 'network error' => [
            static fn (RecordingChannelTransport $transport) => $transport->failWith('connection refused'),
            RegistrationOutcome::NOT_OPERATIONAL,
        ];

        yield 'service outage' => [
            static fn (RecordingChannelTransport $transport) => $transport->response = new ChannelResponse(503, 'application/json', '{}'),
            RegistrationOutcome::NOT_OPERATIONAL,
        ];

        yield 'html error page' => [
            static fn (RecordingChannelTransport $transport) => $transport->response = new ChannelResponse(200, 'text/html', '<html></html>'),
            RegistrationOutcome::UNEXPECTED_CONTENT_TYPE,
        ];

        yield 'invalid json' => [
            static fn (RecordingChannelTransport $transport) => $transport->response = new ChannelResponse(200, 'application/json', 'not json'),
            RegistrationOutcome::MALFORMED,
        ];

        yield 'client rejection' => [
            static fn (RecordingChannelTransport $transport) => $transport->response = new ChannelResponse(403, 'application/json', '{}'),
            RegistrationOutcome::INVALID_KEY,
        ];
    }

    public function testAnUncorrelatedAnswerIsNeverApplied(): void
    {
        $this->respondWith([], ['request_id' => 'someone-elses-request']);

        $outcome = $this->client->activate('CMP-TEST-0000-0000');

        self::assertSame(RegistrationOutcome::MALFORMED, $outcome->status);
        self::assertNull($this->store->package);
    }

    public function testAnImplausibleServerTimeIsRejected(): void
    {
        $this->respondWith([], ['server_time' => self::NOW + ProductProfile::SERVER_TIME_TOLERANCE + 60]);

        self::assertSame(RegistrationOutcome::MALFORMED, $this->client->activate('CMP-TEST-0000-0000')->status);
        self::assertNull($this->store->package);
    }

    public function testAnUnsignedDenialNeverDeletesLocalState(): void
    {
        $this->store->package = $this->factory->sealedPackage();
        $this->store->host = PackageFactory::HOST;
        $this->respondWith([], ['status' => 'revoked']);

        $outcome = $this->client->refresh();

        self::assertSame(RegistrationOutcome::INVALID_KEY, $outcome->status);
        self::assertNotNull($this->store->package);
    }

    public function testAPackageForAnotherHostIsNotApplied(): void
    {
        $this->respondWith(['license_domain' => 'www.example.com']);

        $outcome = $this->client->activate('CMP-TEST-0000-0000');

        self::assertFalse($outcome->successful);
        self::assertNull($this->store->package);
    }

    /**
     * One licence, several bound hosts: this root asked about its own
     * configured host, and that host is one the issuer signed.
     */
    public function testAHostFromTheSignedSetActivatesThisScope(): void
    {
        $this->respondWith();

        $outcome = $this->client->activate('CMP-TEST-0000-0000');

        self::assertTrue($outcome->successful);
        self::assertNotNull($this->store->package);
        self::assertSame(
            [PackageFactory::HOST, PackageFactory::SECOND_HOST],
            $this->store->package->document->boundHosts,
        );
        // The other bound host is a separate identity that activates its own
        // scope; it is not authorised here by being in the same set.
        self::assertSame(PackageFactory::HOST, $this->store->package->document->boundHost);
    }

    /**
     * A package whose signed set does not contain the host this installation
     * asked about is refused, even though every signature is valid.
     */
    public function testAPackageWhoseSignedSetExcludesThisHostIsRefused(): void
    {
        $this->store->package = $this->factory->sealedPackage();
        $this->store->host = PackageFactory::HOST;
        $this->respondWith([
            'license_domain' => 'elsewhere.test',
            'license_domains' => ['elsewhere.test'],
            'license_version' => 9,
        ]);

        $outcome = $this->client->refresh();

        self::assertFalse($outcome->successful);
        self::assertSame(RegistrationOutcome::WRONG_DOMAIN, $outcome->status);
        self::assertSame(7, $this->store->package->document->version, 'Working state must survive.');
    }

    /**
     * The service signs the host set into every current package. One without it
     * is a stale or replayed document and never becomes active state.
     */
    public function testAPackageWithoutTheSignedHostSetIsRefused(): void
    {
        $this->store->package = $this->factory->sealedPackage();
        $this->store->host = PackageFactory::HOST;
        $this->respondWithLegacyDocument();

        $outcome = $this->client->refresh();

        self::assertFalse($outcome->successful);
        self::assertSame(RegistrationOutcome::UNSUPPORTED_SCHEMA, $outcome->status);
        self::assertSame(7, $this->store->package->document->version, 'Working state must survive.');
    }

    public function testABuildWithoutPinnedMaterialNeverSendsTheKey(): void
    {
        $client = $this->client(new DetachedSignature(new KeyDirectory()));

        $outcome = $client->activate('CMP-TEST-0000-0000');

        self::assertSame(RegistrationOutcome::SIGNING_KEY_STORE_EMPTY, $outcome->status);
        self::assertSame([], $this->transport->calls);
        self::assertNull($this->store->package);
    }

    /**
     * A ring that exists but does not contain the offered id is a different
     * condition, and equally never an acceptance.
     */
    public function testAnUnknownKeyIdIsRejectedAfterTheExchange(): void
    {
        $this->respondWithSeal(['key_id' => 'some-other-key']);

        $outcome = $this->client->activate('CMP-TEST-0000-0000');

        self::assertSame(RegistrationOutcome::UNKNOWN_SIGNING_KEY, $outcome->status);
        self::assertFalse($outcome->successful);
        self::assertNull($this->store->package);
    }

    /**
     * The one operation record carries deployment metadata and nothing else.
     * This is the gate that keeps packet material out of ordinary logs.
     */
    public function testTheOperationRecordCarriesNoPacketMaterial(): void
    {
        $logger = new CapturingLogger();
        $client = $this->client(null, $logger);

        $this->respondWith();
        $client->activate('CMP-TEST-0000-0000');

        self::assertNotSame([], $logger->records);

        $serialised = json_encode($logger->records, JSON_THROW_ON_ERROR);

        // Nothing about the key, the packet, the payload or any digest.
        foreach ([
            'CMP-TEST-0000-0000',
            $this->transport->calls[0]['body'],
            'license_payload_b64',
            'license_md5',
            'request_packet',
            'response_packet',
            'request_body',
            'response_body',
            'nonce',
            'signature',
            'request_sha256',
            'response_sha256',
            'license_key_sha256',
            'license_key_length',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialised, $forbidden.' reached the log.');
        }
    }

    /** The record tells an operator whether this build can verify at all. */
    public function testTheOperationRecordReportsKeyStoreReadiness(): void
    {
        $logger = new CapturingLogger();
        $client = $this->client(null, $logger);

        $this->respondWith();
        $client->activate('CMP-TEST-0000-0000');

        $context = $logger->records[array_key_last($logger->records)]['context'];

        self::assertTrue($context['signing_key_store_populated']);
        self::assertSame(1, $context['configured_key_count']);
        self::assertSame(KeyDirectory::class, $context['key_provider']);
        self::assertSame(PackageFactory::KEY_ID, $context['signing_key_id']);
        self::assertTrue($context['requested_key_available']);
        self::assertSame(RegistrationClient::STAGE_ACTIVATION, $context['verification_stage']);
        self::assertSame(ProductProfile::SCHEMA_VERSION, $context['schema_version']);
    }

    /** An empty ring is reported as such, and the attempt stops right there. */
    public function testTheOperationRecordReportsAnEmptyKeyStore(): void
    {
        $logger = new CapturingLogger();
        $client = $this->client(new DetachedSignature(new KeyDirectory()), $logger);

        $client->activate('CMP-TEST-0000-0000');

        $context = $logger->records[array_key_last($logger->records)]['context'];

        self::assertFalse($context['signing_key_store_populated']);
        self::assertSame(0, $context['configured_key_count']);
        self::assertSame(RegistrationClient::STAGE_SIGNING_KEY_STORE, $context['verification_stage']);
        self::assertSame(RegistrationOutcome::SIGNING_KEY_STORE_EMPTY, $context['result_code']);
    }

    public function testAnEmptyKeyIsRejectedWithoutTransport(): void
    {
        self::assertSame(RegistrationOutcome::MISSING_KEY, $this->client->activate(" \t ")->status);
        self::assertSame([], $this->transport->calls);
    }

    public function testAnyDocumentedSuccessStatusAndJsonParametersAreAccepted(): void
    {
        $package = $this->factory->wirePackage();
        $this->transport->responder = static fn (array $packet): ChannelResponse => new ChannelResponse(201, 'application/json; charset=utf-8', json_encode([
            'status' => 'valid',
            'request_id' => $packet['request_id'],
            'server_time' => self::NOW,
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['integrity'],
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($this->client->activate('CMP-TEST-0000-0000')->successful);
    }

    public function testWithoutATrustedHostNothingIsSent(): void
    {
        $store = new InMemoryPackageStore();
        $hosts = new CanonicalHost();
        $client = new RegistrationClient(
            $this->transport,
            $this->factory->reader(),
            new PackageActivator($store, new InMemoryRequestLedger(), $hosts),
            $store,
            $this->factory->identity($store, null),
            $hosts,
            new FrozenClock(self::NOW),
            $this->factory->signatures(),
        );

        self::assertSame(RegistrationOutcome::HOST_UNKNOWN, $client->activate('CMP-TEST-0000-0000')->status);
        self::assertSame([], $this->transport->calls);
    }

    private function client(?DetachedSignature $signatures = null, ?CapturingLogger $logger = null): RegistrationClient
    {
        $hosts = new CanonicalHost();

        return new RegistrationClient(
            $this->transport,
            $this->factory->reader(),
            new PackageActivator($this->store, new InMemoryRequestLedger(), $hosts),
            $this->store,
            $this->factory->identity($this->store),
            $hosts,
            new FrozenClock(self::NOW),
            $signatures ?? $this->factory->signatures(),
            $logger,
        );
    }

    /**
     * Arranges a correlated, fully signed answer for the next call.
     *
     * @param array<string, mixed> $documentOverrides
     * @param array<string, mixed> $envelopeOverrides
     */
    /**
     * Answers with a package whose signed envelope names a different key.
     *
     * @param array<string, mixed> $sealOverrides
     */
    private function respondWithSeal(array $sealOverrides): void
    {
        $package = $this->factory->wirePackage([], $sealOverrides);

        $this->transport->responder = static fn (array $packet): ChannelResponse => new ChannelResponse(200, 'application/json', json_encode([
            'status' => 'valid',
            'request_id' => $packet['request_id'] ?? '',
            'server_time' => self::NOW,
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['integrity'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Answers with a fully signed package from before the host set existed. */
    private function respondWithLegacyDocument(): void
    {
        $package = $this->factory->legacyWirePackage(['license_version' => 9]);

        $this->transport->responder = static fn (array $packet): ChannelResponse => new ChannelResponse(200, 'application/json', json_encode([
            'status' => 'valid',
            'request_id' => $packet['request_id'] ?? '',
            'server_time' => self::NOW,
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['integrity'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function respondWith(array $documentOverrides = [], array $envelopeOverrides = []): void
    {
        $package = $this->factory->wirePackage($documentOverrides);

        $this->transport->responder = static function (array $packet) use ($package, $envelopeOverrides): ChannelResponse {
            $body = array_merge([
                'status' => 'valid',
                'request_id' => $packet['request_id'] ?? '',
                'server_time' => self::NOW,
                'license_payload_b64' => $package['payload'],
                'integrity' => $package['integrity'],
            ], $envelopeOverrides);

            return new ChannelResponse(200, 'application/json', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        };
    }
}
