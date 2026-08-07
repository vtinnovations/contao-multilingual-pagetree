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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Backend;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Vtinnovations\ContaoMultilingualPagetree\Backend\ModuleEntryNotice;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\UsageSignal;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FrozenClock;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\RecordingChannelTransport;

/**
 * The module-entry event: exactly once per authenticated backend session, only
 * from an authenticated local record, and never visible anywhere else.
 */
final class ModuleEntryNoticeTest extends TestCase
{
    private PackageFactory $factory;
    private RecordingChannelTransport $transport;
    private UsageSignal $signal;
    private Session $session;

    protected function setUp(): void
    {
        if (!PackageFactory::isSupported()) {
            self::markTestSkipped('This runtime cannot create signatures.');
        }

        $this->factory = new PackageFactory();
        $this->transport = new RecordingChannelTransport();
        $this->signal = new UsageSignal($this->transport);
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();
    }

    private function notice(?InMemoryPackageStore $store = null, bool $withSession = true): ModuleEntryNotice
    {
        $store ??= $this->activatedStore();

        $request = Request::create('https://'.PackageFactory::HOST.'/contao?do=page');

        if ($withSession) {
            $request->setSession($this->session);
        }

        $stack = new RequestStack();
        $stack->push($request);

        return new ModuleEntryNotice(
            $this->signal,
            $store,
            $this->factory->identity($store),
            new FrozenClock(PackageFactory::NOW),
            $stack,
        );
    }

    private function activatedStore(array $documentOverrides = []): InMemoryPackageStore
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->factory->sealedPackage($documentOverrides);
        $store->host = $store->package->document->boundHost;

        return $store;
    }

    public function testTheFirstEntryOfASessionSendsExactlyOneEvent(): void
    {
        $notice = $this->notice();

        $notice->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        self::assertCount(1, $this->transport->signals);
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $this->transport->signals[0]['url']);
    }

    /** Exactly the two documented fields, and no third one. */
    public function testTheEventCarriesOnlyDomainAndKey(): void
    {
        $notice = $this->notice();
        $notice->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        $payload = json_decode($this->transport->signals[0]['body'], true);

        self::assertSame(['domain', 'key'], array_keys($payload));
        self::assertSame(PackageFactory::HOST, $payload['domain']);
        self::assertSame('CMP-TEST-0000-0000', $payload['key']);
    }

    /**
     * The domain is the current trusted installation host, never the host the
     * record was issued for, so a copied record is visible to the issuer.
     */
    public function testTheEventUsesTheTrustedHostNotTheRecordedOne(): void
    {
        $store = $this->activatedStore(['license_domain' => 'issued-elsewhere.example']);

        $request = Request::create('https://'.PackageFactory::HOST.'/contao?do=page');
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);

        $notice = new ModuleEntryNotice(
            $this->signal,
            $store,
            $this->factory->identity($store),
            new FrozenClock(PackageFactory::NOW),
            $stack,
        );

        $notice->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        $payload = json_decode($this->transport->signals[0]['body'], true);

        self::assertSame(PackageFactory::HOST, $payload['domain']);
        self::assertNotSame('issued-elsewhere.example', $payload['domain']);
    }

    /** Reloads, navigation and AJAX within the same session send nothing more. */
    public function testFurtherEntriesInTheSameSessionSendNothing(): void
    {
        $notice = $this->notice();

        for ($i = 0; $i < 5; ++$i) {
            $notice->noteEntry();
            $this->signal->deliverQueuedModuleEntry();
        }

        self::assertCount(1, $this->transport->signals);
    }

    /** Two service instances sharing one session still emit only once. */
    public function testParallelRequestsInOneSessionSendOnlyOnce(): void
    {
        $first = $this->notice();
        $second = $this->notice();

        $first->noteEntry();
        $second->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        self::assertCount(1, $this->transport->signals);
    }

    public function testANewSessionMayEmitAgain(): void
    {
        $this->notice()->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        // A new login is a new session, and therefore a new marker.
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();

        $this->notice()->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        self::assertCount(2, $this->transport->signals);
    }

    /** No authentic record means no key, so nothing is sent and nothing claimed. */
    public function testAnUnverifiedInstallationSendsNothingAndClaimsNothing(): void
    {
        $empty = new InMemoryPackageStore();

        $this->notice($empty)->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        self::assertCount(0, $this->transport->signals);

        // The marker was not consumed, so once a record exists it can still fire.
        $this->notice()->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        self::assertCount(1, $this->transport->signals);
    }

    /**
     * A record that is authentic but whose entitlement is withheld still emits:
     * that is exactly the case the issuer needs to see.
     */
    public function testAnExpiredButAuthenticRecordStillEmits(): void
    {
        $store = $this->activatedStore([
            'license_expires_at' => PackageFactory::NOW - 10,
            'validation_status' => 'expired',
        ]);

        $this->notice($store)->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        self::assertCount(1, $this->transport->signals);
    }

    /** Without a server-side session "once per session" cannot be guaranteed. */
    public function testWithoutASessionNothingIsSent(): void
    {
        $this->notice(null, false)->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        self::assertCount(0, $this->transport->signals);
    }

    /** A transport failure is silent and is not retried within the session. */
    public function testADeliveryFailureIsNotRetriedInTheSameSession(): void
    {
        $this->transport->failSignals = true;

        $notice = $this->notice();
        $notice->noteEntry();
        $this->signal->deliverQueuedModuleEntry();
        $notice->noteEntry();
        $this->signal->deliverQueuedModuleEntry();

        self::assertSame(1, $this->transport->signalAttempts);
    }

    /** The marker holds no key, host, session identifier or payload. */
    public function testTheSessionMarkerCarriesNoPayload(): void
    {
        $this->notice()->noteEntry();

        foreach ($this->session->all() as $value) {
            self::assertNotSame('CMP-TEST-0000-0000', $value);
            self::assertNotSame(PackageFactory::HOST, $value);
            self::assertIsNotArray($value);
        }

        self::assertStringNotContainsString('CMP-TEST', serialize($this->session->all()));
    }

    /** The per-invocation signal is a different event and is not merged in. */
    public function testTheInvocationSignalRemainsASeparateEvent(): void
    {
        $this->notice()->noteEntry();
        $this->signal->deliverQueuedModuleEntry();
        $this->signal->send(PackageFactory::HOST);

        self::assertCount(2, $this->transport->signals);

        $entry = json_decode($this->transport->signals[0]['body'], true);
        $invocation = json_decode($this->transport->signals[1]['body'], true);

        self::assertSame(['domain', 'key'], array_keys($entry));
        self::assertSame(['project', 'domain'], array_keys($invocation));
        self::assertArrayNotHasKey('key', $invocation);
    }
}
