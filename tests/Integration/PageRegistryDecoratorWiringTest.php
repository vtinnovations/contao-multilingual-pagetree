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

final class PageRegistryDecoratorWiringTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testConstructorAndNamedServiceArgumentsHaveTheSameContract(): void
    {
        $class = (string) file_get_contents(self::ROOT.'/src/Routing/MultilingualPagetreePageRegistryDecorator.php');
        $services = (string) file_get_contents(self::ROOT.'/src/Resources/config/services.yaml');

        self::assertMatchesRegularExpression(
            '/function __construct\(\s*PageRegistry \$inner,\s*ContaoFramework \$framework,\s*EntryPointNormalizer \$entryPoints,\s*LanguageUrlResolver \$languageUrlResolver,/s',
            $class,
        );
        self::assertStringContainsString('$entryPoints: \'@Vtinnovations\\ContaoMultilingualPagetree\\Url\\EntryPointNormalizer\'', $services);
        self::assertStringContainsString('$languageUrlResolver: \'@Vtinnovations\\ContaoMultilingualPagetree\\Url\\LanguageUrlResolver\'', $services);
        self::assertStringContainsString('$this->entryPoints->isRoot(', $class);
        self::assertStringContainsString('$this->languageUrlResolver->forLanguage(', $class);
    }

    public function testThereIsOneDecoratorDefinitionAndNoManualConstruction(): void
    {
        $services = (string) file_get_contents(self::ROOT.'/src/Resources/config/services.yaml');

        self::assertSame(1, substr_count($services, 'Routing\\MultilingualPagetreePageRegistryDecorator:'));

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src')) as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            self::assertStringNotContainsString(
                'new MultilingualPagetreePageRegistryDecorator',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname(),
            );
        }
    }
}
