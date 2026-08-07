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

namespace Vtinnovations\ContaoMultilingualPagetree\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads the bundle services and registers its dedicated logging channels.
 *
 * @internal this class is not a supported extension point
 */
class VtinnovationsContaoMultilingualPagetreeExtension extends Extension implements PrependExtensionInterface
{
    /** Operational events of the bundle. */
    public const CHANNEL = 'contao_multilingual_pagetree';

    /** Integrity scanning, repair planning and cascade execution. */
    public const CHANNEL_INTEGRITY = 'contao_multilingual_pagetree_integrity';

    /**
     * Explicit registration operations only: activate, verify/refresh, replace
     * and remove.
     *
     * It has its own channel and its own file so an operator can read the
     * outcome of an operation they just triggered without the noise of ordinary
     * rendering. Nothing writes to it during frontend requests, ordinary backend
     * rendering, Composer installation, Contao setup or container compilation,
     * so the file only appears once an operation has actually been run.
     */
    public const CHANNEL_REGISTRATION = 'contao_multilingual_pagetree_license';

    /** Where the registration diagnostics are written. */
    public const REGISTRATION_LOG = '%kernel.project_dir%/var/logs/license.log';

    public function getAlias(): string
    {
        return 'contao_multilingual_pagetree';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );

        $loader->load('services.yaml');
    }

    /**
     * Registers the bundle's Monolog channels so operators can route or filter
     * its events without touching unrelated Contao logging.
     */
    public function prepend(ContainerBuilder $container): void
    {
        try {
            $bundles = $container->getParameter('kernel.bundles');
        } catch (\Throwable) {
            return;
        }

        if (!is_array($bundles) || !isset($bundles['MonologBundle'])) {
            return;
        }

        $container->prependExtensionConfig('monolog', [
            'channels' => [self::CHANNEL, self::CHANNEL_INTEGRITY, self::CHANNEL_REGISTRATION],
            'handlers' => [
                // A stream handler creates its file lazily, on the first record
                // it actually writes. Because only the explicit registration
                // operations log to this channel, no file appears during
                // installation, setup, container compilation or rendering.
                'contao_multilingual_pagetree_license' => [
                    'type' => 'stream',
                    'path' => self::REGISTRATION_LOG,
                    'level' => 'info',
                    'channels' => ['type' => 'inclusive', 'elements' => [self::CHANNEL_REGISTRATION]],
                ],
            ],
        ]);
    }
}
