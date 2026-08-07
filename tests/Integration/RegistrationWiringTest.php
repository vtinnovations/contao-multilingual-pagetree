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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Controller\ChannelUpdateController;
use Vtinnovations\ContaoMultilingualPagetree\DependencyInjection\VtinnovationsContaoMultilingualPagetreeExtension;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelTransportInterface;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ChannelUpdateProcessor;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\CurlChannelTransport;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\PackageActivator;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\RegistrationClient;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\UsageSignal;
use Vtinnovations\ContaoMultilingualPagetree\EventListener\UsageSignalListener;
use Vtinnovations\ContaoMultilingualPagetree\Schema\BundleSchema;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Security\ChannelRequestVerifier;
use Vtinnovations\ContaoMultilingualPagetree\Storage\DatabaseRequestLedger;
use Vtinnovations\ContaoMultilingualPagetree\Storage\FilesystemPackageStore;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RequestLedgerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Storage\RootScopedPackageStore;

/**
 * Container, route and migration wiring of the registration path.
 */
final class RegistrationWiringTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new VtinnovationsContaoMultilingualPagetreeExtension())->load([], $container);

        return $container;
    }

    /**
     * @dataProvider expectedServices
     */
    public function testTheRegistrationServicesAreRegistered(string $id): void
    {
        $container = $this->container();

        self::assertTrue(
            $container->hasDefinition($id) || $container->hasAlias($id),
            $id.' must be available in the compiled container.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expectedServices(): iterable
    {
        yield 'policy' => [CapabilityPolicy::class];
        yield 'request verifier' => [ChannelRequestVerifier::class];
        yield 'processor' => [ChannelUpdateProcessor::class];
        yield 'activator' => [PackageActivator::class];
        yield 'client' => [RegistrationClient::class];
        yield 'usage signal' => [UsageSignal::class];
        yield 'signal listener' => [UsageSignalListener::class];
        yield 'controller' => [ChannelUpdateController::class];
        yield 'store alias' => [PackageStoreInterface::class];
        yield 'store' => [FilesystemPackageStore::class];
        yield 'root-scoped store' => [RootScopedPackageStore::class];
        yield 'ledger alias' => [RequestLedgerInterface::class];
        yield 'ledger' => [DatabaseRequestLedger::class];
        yield 'transport alias' => [ChannelTransportInterface::class];
        yield 'transport' => [CurlChannelTransport::class];
    }

    public function testTheControllerIsPublicSoTheRouteCanResolveIt(): void
    {
        self::assertTrue($this->container()->getDefinition(ChannelUpdateController::class)->isPublic());
    }

    public function testStateIsStoredInAPrivateWorkingDirectory(): void
    {
        $directory = (string) $this->container()->getParameter('contao_multilingual_pagetree.licences_directory');

        self::assertStringContainsString('/var/', $directory);
        self::assertStringNotContainsString('/public', $directory);
        self::assertStringNotContainsString('/web', $directory);
        self::assertStringContainsString('%kernel.project_dir%', $directory);
        self::assertStringContainsString('/licences', $directory);
    }

    public function testThePolicyAndTheSignalAreResetBetweenWorkerCycles(): void
    {
        $container = $this->container();

        foreach ([CapabilityPolicy::class, UsageSignal::class] as $id) {
            self::assertArrayHasKey('kernel.reset', $container->getDefinition($id)->getTags(), $id);
        }
    }

    public function testTheSignalListenerRunsAfterTheResponse(): void
    {
        $tags = $this->container()->getDefinition(UsageSignalListener::class)->getTag('kernel.event_listener');

        self::assertSame('kernel.terminate', $tags[0]['event'] ?? null);
    }

    public function testTheRouteUsesTheExactProtocolPathAndDoesNotRestrictTheVerb(): void
    {
        $routes = (string) file_get_contents(self::ROOT.'/src/Resources/config/routes.yaml');

        self::assertStringContainsString('path: '.ProductProfile::ENDPOINT_PATH, $routes);
        self::assertStringContainsString(ChannelUpdateController::class, $routes);
        $endpoint = substr($routes, 0, (int) strpos($routes, 'contao_multilingual_pagetree_root_licence_activate:'));
        self::assertStringNotContainsString('methods:', $endpoint, 'The handler answers a wrong verb with 405 itself.');
        self::assertStringNotContainsString('_scope: backend', $endpoint, 'The endpoint is server-to-server, not a backend route.');
    }

    public function testTheLedgerMigrationMatchesTheLedgerTable(): void
    {
        $migration = (string) file_get_contents(self::ROOT.'/src/Migration/ChannelLedgerMigration.php');

        // The migration builds its statement from the one schema contract, so
        // the table it creates cannot drift from the one the ledger writes to.
        self::assertStringContainsString('BundleSchema::', $migration);
        self::assertSame('tl_multilingual_pagetree_channel_ledger', DatabaseRequestLedger::TABLE);

        // Replay protection lives in these two constraints: one row per request
        // id, and a nonce that can be spent exactly once.
        $sql = BundleSchema::createLedgerSql();

        self::assertStringContainsString(DatabaseRequestLedger::TABLE, $sql);
        self::assertStringContainsString('PRIMARY KEY (request_id)', $sql);
        self::assertStringContainsString('UNIQUE INDEX uniq_cmp_channel_nonce (nonce_digest)', $sql);
    }

    /** No configuration may point the installation at another service. */
    public function testNoDestinationOrKeyMaterialIsConfigurable(): void
    {
        $services = (string) file_get_contents(self::ROOT.'/src/Resources/config/services.yaml');

        // Comments are stripped first: the file banner names the vendor site,
        // which is documentation, not a configurable destination. Asserting
        // against the raw text made this rule fail for the wrong reason.
        $configuration = implode("\n", array_filter(
            explode("\n", $services),
            static fn (string $line): bool => 1 !== preg_match('/^\s*#/', $line),
        ));

        foreach (['v-t.one', 'endpoint', 'public_key', 'key_id', 'api_key', 'secret'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $configuration, 'services.yaml must not configure '.$forbidden.'.');
        }
    }

    /**
     * The build ships approved verification material.
     *
     * This replaces an earlier rule that required the list to be *empty* in the
     * source tree. That rule was wrong: it produced a tree whose every signed
     * workflow failed closed at the key-store stage, and it made the provisioned
     * state untestable. Public keys are not secrets - the private keys stay with
     * the issuer - so the approved public material belongs in the tree, and the
     * release check proves it still reassembles to its recorded fingerprint.
     */
    public function testApprovedVerificationMaterialIsPinnedInTheSourceTree(): void
    {
        $directory = \Vtinnovations\ContaoMultilingualPagetree\Support\KeyDirectory::pinned();

        self::assertGreaterThan(0, \Vtinnovations\ContaoMultilingualPagetree\Support\PinnedMaterial::declaredCount());
        self::assertFalse($directory->isEmpty(), 'A build with an empty ring can verify nothing.');
        self::assertContains('vtone-2026a', $directory->keyIds());
    }

    /** The material is resolved from code constants, never from configuration. */
    public function testTheKeyDirectoryIsBuiltFromCodeOnly(): void
    {
        $services = (string) file_get_contents(self::ROOT.'/src/Resources/config/services.yaml');

        self::assertMatchesRegularExpression(
            '/Support\\\\KeyDirectory:\s*\n\s*factory: \[.*KeyDirectory.*, .pinned.\]/',
            $services,
            'The production directory must come from the pinned factory.',
        );

        // No parameter, environment variable or argument may supply key material.
        self::assertStringNotContainsString('%env(', $services);

        foreach (['PinnedMaterial:', 'VerificationKey:'] as $neverAService) {
            self::assertStringNotContainsString($neverAService, $services, $neverAService.' must not be configurable.');
        }
    }
}
