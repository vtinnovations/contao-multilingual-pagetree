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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Controller\ChannelUpdateController;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelUpdateProcessor;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\PackageActivator;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Security\ChannelRequestVerifier;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FrozenClock;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryRequestLedger;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

/**
 * The public edge: path, verb, media type and size, and nothing else.
 */
final class ChannelUpdateControllerTest extends TestCase
{
    private const NOW = PackageFactory::NOW;

    private PackageFactory $factory;
    private InMemoryPackageStore $store;
    private ChannelUpdateController $controller;

    protected function setUp(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('The sodium extension is not available.');
        }

        $this->factory = new PackageFactory();
        $this->store = new InMemoryPackageStore();
        $ledger = new InMemoryRequestLedger();
        $hosts = new CanonicalHost();

        $this->controller = new ChannelUpdateController(new ChannelUpdateProcessor(
            new ChannelRequestVerifier($this->factory->signatures(), $hosts),
            $this->factory->reader(),
            new PackageActivator($this->store, $ledger, $hosts),
            $ledger,
            $this->factory->identity($this->store),
            new FrozenClock(self::NOW),
        ));
    }

    public function testTheDocumentedPathIsExactlyTheProtocolPath(): void
    {
        self::assertSame('/rest/api/v1/contao-multilingual-pagetree-license-updater', ProductProfile::ENDPOINT_PATH);

        $routes = (string) file_get_contents(__DIR__.'/../../src/Resources/config/routes.yaml');

        self::assertStringContainsString('path: '.ProductProfile::ENDPOINT_PATH, $routes);
    }

    public function testASignedPostIsApplied(): void
    {
        $body = $this->body();
        $response = ($this->controller)($this->request('POST', $body));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'updated', 'request_id' => 'req-00000001', 'license_version' => 7],
            json_decode((string) $response->getContent(), true),
        );
        self::assertNotNull($this->store->package);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * @dataProvider readMethods
     */
    public function testANonPostVerbIsAnsweredWith405(string $method): void
    {
        $response = ($this->controller)($this->request($method, ''));

        self::assertSame(405, $response->getStatusCode(), 'A wrong verb must not look like a missing endpoint.');
        self::assertSame('POST', $response->headers->get('Allow'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
    }

    public function testAnUnsupportedMediaTypeIsRefused(): void
    {
        $response = ($this->controller)($this->request('POST', $this->body(), 'application/x-www-form-urlencoded'));

        self::assertSame(415, $response->getStatusCode());
    }

    public function testAJsonMediaTypeWithACharsetIsAccepted(): void
    {
        $response = ($this->controller)($this->request('POST', $this->body(), 'application/json; charset=utf-8'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnOversizedBodyIsRefusedBeforeParsing(): void
    {
        $response = ($this->controller)($this->request('POST', str_repeat('a', ProductProfile::MAX_REQUEST_BYTES + 10)));

        self::assertSame(413, $response->getStatusCode());
        self::assertNull($this->store->package);
    }

    public function testADeclaredOversizedLengthIsRefused(): void
    {
        $request = $this->request('POST', $this->body());
        $request->headers->set('Content-Length', (string) (ProductProfile::MAX_REQUEST_BYTES + 1));

        self::assertSame(413, ($this->controller)($request)->getStatusCode());
    }

    public function testAnUnsignedPostIsRefusedGenerically(): void
    {
        $request = Request::create(ProductProfile::ENDPOINT_PATH, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => PackageFactory::HOST,
        ], $this->body());

        $response = ($this->controller)($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['status' => 'rejected'], json_decode((string) $response->getContent(), true));
        self::assertNull($this->store->package);
    }

    public function testAnotherPathIsNotThisEndpoint(): void
    {
        $body = $this->body();
        $request = Request::create('/rest/api/v1/something-else', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => PackageFactory::HOST,
        ], $body);

        self::assertSame(404, ($this->controller)($request)->getStatusCode());
    }

    /** The edge must stay thin: no verification or storage logic of its own. */
    public function testTheEdgeDelegatesEverythingSensitive(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Controller/ChannelUpdateController.php');

        foreach (['sodium_', 'md5(', 'base64_decode', 'file_put_contents', 'fopen(', 'hash_equals', 'KeyDirectory', 'PackageReader'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, 'The public edge must not contain '.$forbidden.'.');
        }
    }

    private function body(): string
    {
        return json_encode($this->factory->updateBody(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function request(string $method, string $body, string $contentType = 'application/json'): Request
    {
        $headers = $this->factory->requestHeaders($method, ProductProfile::ENDPOINT_PATH, $body, self::NOW);
        $server = [
            'CONTENT_TYPE' => $contentType,
            'HTTP_HOST' => PackageFactory::HOST,
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create(ProductProfile::ENDPOINT_PATH, $method, [], [], [], $server, $body);
    }
}
