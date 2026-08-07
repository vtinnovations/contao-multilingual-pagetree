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

namespace Vtinnovations\ContaoMultilingualPagetree\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use Vtinnovations\ContaoMultilingualPagetree\VtinnovationsContaoMultilingualPagetreeBundle;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(VtinnovationsContaoMultilingualPagetreeBundle::class)
                ->setLoadAfter([
                    ContaoCoreBundle::class,
                    'Contao\NewsBundle\ContaoNewsBundle',
                    'Contao\CalendarBundle\ContaoCalendarBundle',
                    'Contao\FaqBundle\ContaoFaqBundle'
                ]),
        ];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): RouteCollection
    {
        $file = __DIR__.'/../Resources/config/routes.yaml';
        $loader = $resolver->resolve($file);

        if (null === $loader) {
            // A missing YAML loader must not trigger licensing or other runtime
            // work. Returning an empty collection keeps discovery deterministic;
            // server diagnostics will make the absent route visible.
            return new RouteCollection();
        }

        $routes = $loader->load($file);

        return $routes instanceof RouteCollection ? $routes : new RouteCollection();
    }
}
