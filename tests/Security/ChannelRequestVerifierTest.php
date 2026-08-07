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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Security\ChannelRequestRejected;
use Vtinnovations\ContaoMultilingualPagetree\Security\ChannelRequestVerifier;
use Vtinnovations\ContaoMultilingualPagetree\Support\DetachedSignature;
use Vtinnovations\ContaoMultilingualPagetree\Support\KeyDirectory;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

final class ChannelRequestVerifierTest extends TestCase
{
    private const NOW = PackageFactory::NOW;
    private const PATH = ProductProfile::ENDPOINT_PATH;

    private PackageFactory $factory;
    private ChannelRequestVerifier $verifier;

    protected function setUp(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('The sodium extension is not available.');
        }

        $this->factory = new PackageFactory();
        $this->verifier = new ChannelRequestVerifier($this->factory->signatures(), new CanonicalHost());
    }

    public function testACorrectlySignedRequestIsAccepted(): void
    {
        $body = $this->body();
        $verified = $this->verifier->verify('POST', self::PATH, $this->factory->requestHeaders('POST', self::PATH, $body), $body, self::NOW);

        self::assertSame('req-00000001', $verified->requestId);
        self::assertSame(PackageFactory::HOST, $verified->host);
        self::assertSame(hash('sha256', $body), $verified->fingerprint);
        self::assertSame(hash('sha256', 'nonce-000000000001'), $verified->nonceDigest);
    }

    public function testAnUnsignedRequestIsRejected(): void
    {
        $body = $this->body();

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, [], $body, self::NOW);
    }

    public function testABuildWithoutPinnedMaterialAcceptsNothing(): void
    {
        $verifier = new ChannelRequestVerifier(new DetachedSignature(new KeyDirectory()), new CanonicalHost());
        $body = $this->body();

        $this->expectException(ChannelRequestRejected::class);
        $verifier->verify('POST', self::PATH, $this->factory->requestHeaders('POST', self::PATH, $body), $body, self::NOW);
    }

    public function testASignatureForAnotherPathIsRejected(): void
    {
        $body = $this->body();
        $headers = $this->factory->requestHeaders('POST', '/some/other/path', $body);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body, self::NOW);
    }

    public function testASignatureForAnotherMethodIsRejected(): void
    {
        $body = $this->body();
        $headers = $this->factory->requestHeaders('PUT', self::PATH, $body);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body, self::NOW);
    }

    public function testAModifiedBodyIsRejected(): void
    {
        $body = $this->body();
        $headers = $this->factory->requestHeaders('POST', self::PATH, $body);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body.' ', self::NOW);
    }

    public function testAStaleTimestampIsRejected(): void
    {
        $body = $this->body(['timestamp' => self::NOW - 3600]);
        $headers = $this->factory->requestHeaders('POST', self::PATH, $body, self::NOW - 3600);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body, self::NOW);
    }

    public function testAFutureTimestampIsRejected(): void
    {
        $future = self::NOW + ProductProfile::REQUEST_FUTURE_TOLERANCE + 30;
        $body = $this->body(['timestamp' => $future]);
        $headers = $this->factory->requestHeaders('POST', self::PATH, $body, $future);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body, self::NOW);
    }

    /**
     * @dataProvider mismatchedBodies
     *
     * @param array<string, mixed> $overrides
     */
    public function testDuplicatedFieldsMustMatchTheSignedHeaders(array $overrides): void
    {
        $body = $this->body($overrides);
        $headers = $this->factory->requestHeaders('POST', self::PATH, $body);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body, self::NOW);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function mismatchedBodies(): iterable
    {
        yield 'other request id' => [['request_id' => 'req-00000002']];
        yield 'other nonce' => [['nonce' => 'nonce-000000000002']];
        yield 'other timestamp' => [['timestamp' => PackageFactory::NOW - 5]];
        yield 'other action' => [['action' => 'license_delete']];
        yield 'other project' => [['project' => 'Another Product']];
        yield 'other slug' => [['project_slug' => 'another-product']];
        yield 'other product id' => [['product_id' => 'vt-other']];
        yield 'missing domain' => [['domain' => null]];
        yield 'wildcard domain' => [['domain' => '*.example.com']];
    }

    public function testAnUnknownKeyIdIsRejected(): void
    {
        $other = new PackageFactory('other-key');
        $body = $this->body();
        $headers = $other->requestHeaders('POST', self::PATH, $body);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body, self::NOW);
    }

    public function testAnOversizedBodyIsRejected(): void
    {
        $body = str_repeat('a', ProductProfile::MAX_REQUEST_BYTES + 1);
        $headers = $this->factory->requestHeaders('POST', self::PATH, $body);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body, self::NOW);
    }

    public function testANonJsonBodyIsRejected(): void
    {
        $body = 'not json at all';
        $headers = $this->factory->requestHeaders('POST', self::PATH, $body);

        $this->expectException(ChannelRequestRejected::class);
        $this->verifier->verify('POST', self::PATH, $headers, $body, self::NOW);
    }

    /** Nothing about the request's claimed origin is consulted. */
    public function testTheVerifierDoesNotLookAtOriginOrReferer(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Security/ChannelRequestVerifier.php');

        foreach (['getClientIp', 'HTTP_ORIGIN', "'Origin'", "'Referer'", 'User-Agent', 'gethostbyaddr'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, $forbidden.' must not influence authentication.');
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function body(array $overrides = []): string
    {
        return json_encode($this->factory->updateBody($overrides), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
