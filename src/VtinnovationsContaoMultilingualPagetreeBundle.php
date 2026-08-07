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

namespace Vtinnovations\ContaoMultilingualPagetree;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Vtinnovations\ContaoMultilingualPagetree\DependencyInjection\VtinnovationsContaoMultilingualPagetreeExtension;

class VtinnovationsContaoMultilingualPagetreeBundle extends Bundle
{
    private ?ExtensionInterface $containerExtension = null;

    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->containerExtension ??= new VtinnovationsContaoMultilingualPagetreeExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
