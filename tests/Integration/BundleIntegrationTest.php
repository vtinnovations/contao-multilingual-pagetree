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
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
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
        if (interface_exists(BundlePluginInterface::class)) {
            self::assertInstanceOf(BundlePluginInterface::class, new Plugin());
        } else {
            // The Manager API is supplied by Contao Manager, not by an
            // installed frontend application. Its class must not be loaded in
            // a core-only test stack where that optional API is absent.
            self::assertSame(
                Plugin::class,
                'Vtinnovations\\ContaoMultilingualPagetree\\ContaoManager\\Plugin',
            );
        }
        self::assertSame('VtinnovationsContaoMultilingualPagetreeBundle', $bundle->getName());
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
        $conditionals = $container->getDefinition(PageAvailabilityResolver::class)->getInstanceofConditionals();
        self::assertArrayHasKey(TranslationFieldPolicyContributorInterface::class, $conditionals);
        self::assertArrayHasKey(IntegrityRuleInterface::class, $conditionals);

        $contributors = $container->getDefinition(
            'Vtinnovations\\ContaoMultilingualPagetree\\Translation\\TranslationFieldRegistry',
        )->getArgument('$contributors');
        self::assertInstanceOf(TaggedIteratorArgument::class, $contributors);
        self::assertSame('contao_multilingual_pagetree.translation_field_policy_contributor', $contributors->getTag());
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
