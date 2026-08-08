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

use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vtinnovations\ContaoMultilingualPagetree\ContaoManager\Plugin;
use Vtinnovations\ContaoMultilingualPagetree\DependencyInjection\VtinnovationsContaoMultilingualPagetreeExtension;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldPolicyContributorInterface;
use Vtinnovations\ContaoMultilingualPagetree\VtinnovationsContaoMultilingualPagetreeBundle;

final class BundleIntegrationTest extends TestCase
{
    public function testBundleAndManagerPluginExposeTheRenamedIdentity(): void
    {
        $bundle = new VtinnovationsContaoMultilingualPagetreeBundle();

        self::assertDirectoryExists($bundle->getPath().'/contao');
        self::assertInstanceOf(BundlePluginInterface::class, new Plugin());
        self::assertSame('VtinnovationsContaoMultilingualPagetree', $bundle->getName());
    }

    public function testBundleExplicitlyReturnsAndReusesItsApprovedContainerExtension(): void
    {
        $bundle = new VtinnovationsContaoMultilingualPagetreeBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(VtinnovationsContaoMultilingualPagetreeExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
        self::assertSame('contao_multilingual_pagetree', $extension->getAlias());
        self::assertNotSame('vtinnovations_contao_multilingual_pagetree', $extension->getAlias());

        $container = new ContainerBuilder();
        $extension->load([], $container);

        self::assertTrue($container->hasDefinition('Vtinnovations\\ContaoMultilingualPagetree\\Integrity\\IntegrityScanner'));
    }

    public function testDependencyInjectionExtensionLoadsOwnedServicesAndTags(): void
    {
        $container = new ContainerBuilder();
        $extension = new VtinnovationsContaoMultilingualPagetreeExtension();
        $extension->load([], $container);

        self::assertSame('contao_multilingual_pagetree', $extension->getAlias());
        self::assertTrue($container->hasDefinition('Vtinnovations\\ContaoMultilingualPagetree\\Integrity\\IntegrityScanner'));
        self::assertTrue($container->hasDefinition('Vtinnovations\\ContaoMultilingualPagetree\\Translation\\TranslationFieldRegistry'));
        self::assertArrayHasKey(
            TranslationFieldPolicyContributorInterface::class,
            $container->getAutoconfiguredInstanceof(),
        );
        self::assertArrayHasKey(IntegrityRuleInterface::class, $container->getAutoconfiguredInstanceof());
    }

    public function testEveryConfiguredClassResourceExists(): void
    {
        $container = new ContainerBuilder();
        (new VtinnovationsContaoMultilingualPagetreeExtension())->load([], $container);

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract()) {
                continue;
            }
            $class = $definition->getClass() ?? $id;
            if (!is_string($class) || !str_starts_with($class, 'Vtinnovations\\ContaoMultilingualPagetree\\')) {
                continue;
            }
            self::assertTrue(class_exists($class) || interface_exists($class), sprintf('Configured class %s must autoload.', $class));
        }
    }
}
