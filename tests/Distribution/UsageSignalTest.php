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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\UsageSignal;
use Vtinnovations\ContaoMultilingualPagetree\EventListener\UsageSignalListener;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\RecordingChannelTransport;

/**
 * The invocation signal: two values, one call, no consequences.
 */
final class UsageSignalTest extends TestCase
{
    private RecordingChannelTransport $transport;
    private UsageSignal $signal;

    protected function setUp(): void
    {
        $this->transport = new RecordingChannelTransport();
        $this->signal = new UsageSignal($this->transport);
    }

    public function testExactlyTwoValuesAreSentToTheFixedEndpoint(): void
    {
        $this->signal->send('example.com');

        self::assertCount(1, $this->transport->signals);
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $this->transport->signals[0]['url']);
        self::assertSame(
            ['project' => ProductProfile::PROJECT, 'domain' => 'example.com'],
            json_decode($this->transport->signals[0]['body'], true),
        );
    }

    /** No key, no document, no user, no path, no session, no address. */
    public function testTheBodyCarriesNothingElse(): void
    {
        $this->signal->send('example.com');
        $body = (string) $this->transport->signals[0]['body'];

        foreach (['license', 'signature', 'md5', 'key', 'user', 'session', 'cookie', 'path', 'ip'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($body), $forbidden.' must never be transmitted.');
        }
    }

    public function testTheSignalIsSentAtMostOncePerInvocation(): void
    {
        $this->signal->send('example.com');
        $this->signal->send('example.com');
        $this->signal->send('other.example');

        self::assertCount(1, $this->transport->signals);

        $this->signal->reset();
        $this->signal->send('example.com');

        self::assertCount(2, $this->transport->signals);
    }

    public function testAFailingSignalIsSwallowed(): void
    {
        $this->transport->failWith('no route to host');

        $this->signal->send('example.com');

        self::assertTrue($this->signal->hasSent());
    }

    public function testTheListenerSignalsAfterTheResponseWasSent(): void
    {
        $store = new InMemoryPackageStore();
        $identity = (new PackageFactory())->identity($store);
        $listener = new UsageSignalListener($this->signal, $identity);

        $listener($this->event('/some/page'));

        self::assertCount(1, $this->transport->signals);
        self::assertSame(
            ['project' => ProductProfile::PROJECT, 'domain' => PackageFactory::HOST],
            json_decode($this->transport->signals[0]['body'], true),
        );
    }

    public function testTheUpdateEndpointDoesNotTriggerASignal(): void
    {
        $store = new InMemoryPackageStore();
        $listener = new UsageSignalListener($this->signal, (new PackageFactory())->identity($store));

        $listener($this->event(ProductProfile::ENDPOINT_PATH));

        self::assertSame([], $this->transport->signals);
    }

    private function event(string $path): TerminateEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new TerminateEvent($kernel, Request::create('https://'.PackageFactory::HOST.$path), new Response());
    }
}
