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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\Cmf\Component\Routing\RouteProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PublicationChecker;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\IncomingLanguageResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageDomainNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Routing\MultilingualPagetreeRouteProviderDecorator;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeTranslationRecordLocator;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryLanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PageModelMockTrait;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

class MultilingualPagetreeRouteProviderDecoratorTest extends TestCase
{
    use PageModelMockTrait;

    /** Requirement 21 */
    public function testStrictModeGeneratesARouteOnlyForAnAvailableTranslation(): void
    {
        $collection = $this->collect('strict', [
            'tl_page_translation|10|de' => $this->translation(10, 'ueber-uns'),
        ]);

        $this->assertNotNull($collection->get('tl_page.10.de'));
        $this->assertSame('/de/ueber-uns', $collection->get('tl_page.10.de')->getPath());
        $this->assertSame('de', $collection->get('tl_page.10.de')->getDefault('_contao_multilingual_pagetree'));
        $this->assertNull($collection->get('tl_page.10.de')->getDefault('_contao_multilingual_pagetree_fallback'));
    }

    /** Requirements 22 and 27 */
    public function testStrictModeGeneratesNoSourceAliasFallbackRoute(): void
    {
        $collection = $this->collect('strict', []);

        $this->assertNull($collection->get('tl_page.10.de'));
        $this->assertSame([], $this->pathsOfPrefix($collection, '/de/'));
    }

    /** Requirement 27: an unpublished translated alias is never exposed. */
    public function testUnpublishedTranslatedAliasIsNeverExposed(): void
    {
        $unpublished = $this->translation(10, 'ueber-uns', ['published' => '']);

        $strict = $this->collect('strict', ['tl_page_translation|10|de' => $unpublished]);
        $this->assertSame([], $this->pathsOfPrefix($strict, '/de/'));

        $fallback = $this->collect('fallback', ['tl_page_translation|10|de' => $unpublished]);
        $this->assertSame('/de/about-us', $fallback->get('tl_page.10.de')->getPath());
        $this->assertNotContains('/de/ueber-uns', $this->pathsOfPrefix($fallback, '/de/'));
    }

    /** Requirement 23 */
    public function testFallbackModeUsesTheTranslatedAliasWhenAvailable(): void
    {
        $collection = $this->collect('fallback', [
            'tl_page_translation|10|de' => $this->translation(10, 'ueber-uns'),
        ]);

        $this->assertSame('/de/ueber-uns', $collection->get('tl_page.10.de')->getPath());
        $this->assertNull($collection->get('tl_page.10.de')->getDefault('_contao_multilingual_pagetree_fallback'));
    }

    /** Requirement 24 */
    public function testFallbackModeGeneratesAPrefixedSourceAliasRoute(): void
    {
        $collection = $this->collect('fallback', []);
        $route = $collection->get('tl_page.10.de');

        $this->assertNotNull($route);
        $this->assertSame('/de/about-us', $route->getPath());
        $this->assertSame('de', $route->getDefault('_locale'));
        $this->assertTrue($route->getDefault('_contao_multilingual_pagetree_fallback'), 'The resolver must know that source content is intended.');
    }

    /** Requirements 25 and 26 */
    public function testDefaultLanguageStaysUnprefixedAndNonDefaultStaysPrefixed(): void
    {
        $collection = $this->collect('fallback', [
            'tl_page_translation|10|de' => $this->translation(10, 'ueber-uns'),
        ]);

        $this->assertSame('/about-us', $collection->get('tl_page.10')->getPath());
        $this->assertSame('en', $collection->get('tl_page.10')->getDefault('_contao_multilingual_pagetree'));
        $this->assertSame('/de/ueber-uns', $collection->get('tl_page.10.de')->getPath());
        $this->assertNull($collection->get('tl_page.10.en'), 'The default language never gets an ordinary prefixed route.');
    }

    /** Requirements 28 and 29 */
    public function testAnObsoleteFallbackAliasRedirectsToTheTranslatedAlias(): void
    {
        $collection = $this->collect('fallback', [
            'tl_page_translation|10|de' => $this->translation(10, 'ueber-uns'),
        ]);

        $redirect = $collection->get('contao_multilingual_pagetree.redirect.10.de.obsolete');

        $this->assertNotNull($redirect);
        $this->assertSame('/de/about-us', $redirect->getPath());
        $this->assertTrue($redirect->getDefault('_contao_multilingual_pagetree_canonical_redirect'));
        $this->assertSame('de', $redirect->getDefault('_contao_multilingual_pagetree'));
        $this->assertSame('/de/ueber-uns', $collection->get('tl_page.10.de')->getPath(), 'The translated alias is canonical.');
    }

    /** Requirement 30: no redirect may point at itself. */
    public function testNoRedirectIsCreatedWhenTheTranslatedAliasEqualsTheSourceAlias(): void
    {
        $collection = $this->collect('fallback', [
            'tl_page_translation|10|de' => $this->translation(10, 'about-us'),
        ]);

        $this->assertSame('/de/about-us', $collection->get('tl_page.10.de')->getPath());
        $this->assertNull($collection->get('contao_multilingual_pagetree.redirect.10.de.obsolete'));
    }

    public function testNoObsoleteRedirectIsCreatedForFallbackPages(): void
    {
        $collection = $this->collect('fallback', []);

        $this->assertNull($collection->get('contao_multilingual_pagetree.redirect.10.de.obsolete'));
    }

    /** Point 1: the prefixed default language stays redirect-only. */
    public function testThePrefixedDefaultLanguageRemainsRedirectOnly(): void
    {
        $collection = $this->collect('fallback', []);
        $redirect = $collection->get('contao_multilingual_pagetree.redirect.10.en');

        $this->assertNotNull($redirect);
        $this->assertSame('/en/about-us', $redirect->getPath());
        $this->assertTrue($redirect->getDefault('_contao_multilingual_pagetree_canonical_redirect'));
    }

    /** Requirement 31 */
    public function testRootPagesFollowTheSamePolicy(): void
    {
        $root = $this->mockRootPage(1, 'en');
        $inner = $this->innerProvider(['tl_page.1.root' => new Route('/', ['pageModel' => $root])]);

        $fallback = $this->decorator($inner, 'fallback', [])->getRouteCollectionForRequest(Request::create('/de/'));
        $this->assertSame('/de/', $fallback->get('tl_page.1.de.root')->getPath());
        $this->assertTrue($fallback->get('tl_page.1.de.root')->getDefault('_contao_multilingual_pagetree_fallback'));

        $strict = $this->decorator($this->innerProvider(['tl_page.1.root' => new Route('/', ['pageModel' => $root])]), 'strict', [])
            ->getRouteCollectionForRequest(Request::create('/de/'));
        $this->assertNull($strict->get('tl_page.1.de.root'));
    }

    public function testCustomLanguageHostBootstrapsAnEmptyContaoRootCollection(): void
    {
        $root = $this->mockRootPage(1, 'de', ['dns' => 'bauland.taheri.cool']);
        $inner = $this->innerProvider(
            ['tl_page.1.root' => new Route('/', ['pageModel' => $root], [], [], 'bauland.taheri.cool')],
            true,
        );
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'de', 'default')->add(1, 'ru', 'fallback');
        $urlPolicy = new CanonicalUrlPolicy();
        $urlResolver = new InMemoryLanguageUrlResolver(
            [1 => ['host' => 'bauland.taheri.cool', 'secure' => true, 'language' => 'de']],
            [1 => [[
                'id' => 3,
                'language' => 'ru',
                'urlProtocol' => '',
                'urlDomain' => 'bauland-ru.taheri.cool',
                'urlEntryPoint' => '',
                'published' => true,
            ]]],
        );
        $availability = new PageAvailabilityResolver(
            $registry,
            new FakeTranslationRecordLocator([]),
            new TranslationOverlayResolver(new TranslationFieldRegistry(), new FieldStateMap()),
            $urlPolicy,
            new PublicationChecker(),
            null,
            null,
            $urlResolver,
        );
        $languageHelper = new LanguageHelper(
            new RequestStack(),
            $urlPolicy,
            $registry,
            $availability,
            new PublicationChecker(),
            new IncomingLanguageResolver($urlResolver),
            $urlResolver,
        );
        $provider = new MultilingualPagetreeRouteProviderDecorator(
            $inner,
            $languageHelper,
            $urlPolicy,
            $availability,
            $urlResolver,
        );

        $collection = $provider->getRouteCollectionForRequest(Request::create('https://bauland-ru.taheri.cool/'));
        $route = $collection->get('tl_page.1.root');

        $this->assertNotNull($route);
        $this->assertSame('/', $route->getPath());
        $this->assertSame('bauland-ru.taheri.cool', $route->getHost());
        $this->assertSame('ru', $route->getDefault('_contao_multilingual_pagetree'));

        $stale = $provider->getRouteCollectionForRequest(Request::create('https://bauland-ru.taheri.cool/ru'));
        $staleRoute = $stale->get('tl_page.1.root');

        $this->assertNotNull($staleRoute);
        $this->assertSame('/ru', $staleRoute->getPath());
        $this->assertTrue($staleRoute->getDefault('_contao_multilingual_pagetree_canonical_redirect'));
    }

    /** Requirement 32: route construction is deterministic. */
    public function testRouteGenerationIsDeterministic(): void
    {
        $translations = ['tl_page_translation|10|de' => $this->translation(10, 'ueber-uns')];

        $first = array_map(
            static fn (Route $route): string => $route->getPath(),
            $this->collect('fallback', $translations)->all(),
        );
        $second = array_map(
            static fn (Route $route): string => $route->getPath(),
            $this->collect('fallback', $translations)->all(),
        );

        $this->assertSame($first, $second);
    }

    /**
     * A redirect must never shadow a page that legitimately owns the path, so
     * aliases colliding across pages stay resolvable.
     */
    public function testARedirectNeverShadowsAPageOwningThePath(): void
    {
        $first = $this->mockRegularPage(10, 1, 'about-us');
        // A second page whose German translation is exactly the obsolete path.
        $second = $this->mockRegularPage(11, 1, 'contact');

        $inner = $this->innerProvider([
            'tl_page.10' => new Route('/about-us', ['pageModel' => $first]),
            'tl_page.11' => new Route('/contact', ['pageModel' => $second]),
        ]);

        $collection = $this->decorator($inner, 'fallback', [
            'tl_page_translation|10|de' => $this->translation(10, 'ueber-uns'),
            'tl_page_translation|11|de' => $this->translation(11, 'about-us'),
        ])->getRouteCollectionForRequest(Request::create('/de/about-us'));

        $this->assertSame('/de/about-us', $collection->get('tl_page.11.de')->getPath());
        $this->assertNull(
            $collection->get('contao_multilingual_pagetree.redirect.10.de.obsolete'),
            'The obsolete alias of page 10 is the canonical alias of page 11.',
        );
    }

    public function testTheMatchingLocalisedRouteReplacesTheRequestedPageRoute(): void
    {
        $collection = $this->decorator(
            $this->innerProvider(['tl_page.10' => new Route('/about-us', ['pageModel' => $this->mockRegularPage(10, 1, 'about-us')])]),
            'fallback',
            [],
        )->getRouteCollectionForRequest(Request::create('/de/about-us'));

        $matched = $collection->get('tl_page.10');

        $this->assertSame('/de/about-us', $matched->getPath());
        $this->assertSame('de', $matched->getDefault('_contao_multilingual_pagetree'));
        $this->assertTrue($matched->getDefault('_contao_multilingual_pagetree_fallback'));
    }

    public function testAnUnpublishedSourcePageGetsNoLanguageRoutes(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['published' => '']);
        $inner = $this->innerProvider(['tl_page.10' => new Route('/about-us', ['pageModel' => $page])]);

        $collection = $this->decorator($inner, 'fallback', [])->getRouteCollectionForRequest(Request::create('/de/about-us'));

        $this->assertNull($collection->get('tl_page.10.de'));
        $this->assertSame([], $this->pathsOfPrefix($collection, '/de/'));
    }

    /**
     * @param array<string, object> $translations
     */
    private function collect(string $mode, array $translations): RouteCollection
    {
        $page = $this->mockRegularPage(10, 1, 'about-us');
        $inner = $this->innerProvider(['tl_page.10' => new Route('/about-us', ['pageModel' => $page])]);

        return $this->decorator($inner, $mode, $translations)->getRouteCollectionForRequest(Request::create('/about-us'));
    }

    /**
     * @param array<string, Route> $routes
     */
    private function innerProvider(array $routes, bool $emptyRequestCollection = false): RouteProviderInterface
    {
        return new class($routes, $emptyRequestCollection) implements RouteProviderInterface {
            /**
             * @param array<string, Route> $routes
             */
            public function __construct(private readonly array $routes, private readonly bool $emptyRequestCollection)
            {
            }

            public function getRouteCollectionForRequest(Request $request): RouteCollection
            {
                $collection = new RouteCollection();

                if ($this->emptyRequestCollection) {
                    return $collection;
                }

                foreach ($this->routes as $name => $route) {
                    $collection->add($name, clone $route);
                }

                return $collection;
            }

            public function getRouteByName($name): Route
            {
                if (!isset($this->routes[$name])) {
                    throw new \Symfony\Component\Routing\Exception\RouteNotFoundException((string) $name);
                }

                return $this->routes[$name];
            }

            public function getRoutesByNames($names = null): iterable
            {
                return $this->routes;
            }
        };
    }

    /**
     * @param array<string, object> $translations
     */
    private function decorator(RouteProviderInterface $inner, string $mode, array $translations): MultilingualPagetreeRouteProviderDecorator
    {
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default')->add(1, 'de', $mode);
        $urlPolicy = new CanonicalUrlPolicy();

        $resolver = new PageAvailabilityResolver(
            $registry,
            new FakeTranslationRecordLocator($translations),
            new TranslationOverlayResolver(new TranslationFieldRegistry(), new FieldStateMap()),
            $urlPolicy,
            new PublicationChecker(),
        );

        $urlResolver = new LanguageUrlResolver(
            new LanguageDomainNormalizer(new CanonicalHost()),
            new EntryPointNormalizer(),
        );

        $languageHelper = new LanguageHelper(
            new RequestStack(),
            $urlPolicy,
            $registry,
            $resolver,
            new PublicationChecker(),
            new IncomingLanguageResolver($urlResolver),
            $urlResolver,
        );

        return new MultilingualPagetreeRouteProviderDecorator($inner, $languageHelper, $urlPolicy, $resolver);
    }

    /**
     * @return list<string>
     */
    private function pathsOfPrefix(RouteCollection $collection, string $prefix): array
    {
        $paths = [];

        foreach ($collection->all() as $route) {
            $path = $route->getPath();

            if (str_starts_with($path, $prefix) && !str_contains($path, '{parameters}')) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function translation(int $pageId, string $alias, array $overrides = []): FakeModel
    {
        return new FakeModel('tl_page_translation', array_merge([
            'id' => 900 + $pageId,
            'pid' => $pageId,
            'language' => 'de',
            'published' => '1',
            'start' => '',
            'stop' => '',
            'fieldStates' => json_encode(['alias' => FieldStateMap::CUSTOM], JSON_THROW_ON_ERROR),
            'alias' => $alias,
        ], $overrides));
    }
}
