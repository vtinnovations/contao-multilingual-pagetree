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
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageContext;
use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendTranslationScope;
use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendUrlBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageFallback;

/**
 * The tab URLs themselves: the default and the additional-language URL must be
 * observably different, and only the canonical parameter may ever be generated.
 */
class BackendUrlBuilderTest extends TestCase
{
    private BackendUrlBuilder $urls;

    protected function setUp(): void
    {
        $this->urls = new BackendUrlBuilder(new class() implements RouterInterface {
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                ksort($parameters);

                return '/contao?'.http_build_query($parameters);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            public function getRouteCollection(): \Symfony\Component\Routing\RouteCollection
            {
                return new \Symfony\Component\Routing\RouteCollection();
            }

            public function match(string $pathinfo): array
            {
                return [];
            }
        });
    }

    /** The core symptom: the two tab URLs must not be the same request. */
    public function testTheDefaultAndAdditionalLanguageUrlsDiffer(): void
    {
        $default = $this->urls->forDefaultLanguage('tl_page', 17, ['act' => 'edit']);
        $english = $this->urls->forLanguage('tl_page', 17, 'en', 1, ['act' => 'edit']);

        $this->assertNotSame($default, $english);
        $this->assertStringContainsString('contao_multilingual_pagetree_lang=en', $english);
        $this->assertStringContainsString('contao_multilingual_pagetree_root=1', $english);
    }

    /** The default tab must actively drop the additional-language context. */
    public function testTheDefaultUrlCarriesNoLanguageContext(): void
    {
        $default = $this->urls->forDefaultLanguage('tl_page', 17, ['act' => 'edit']);

        $this->assertStringNotContainsString(BackendLanguageContext::LANGUAGE_PARAMETER, $default);
        $this->assertStringNotContainsString(BackendLanguageContext::ROOT_PARAMETER, $default);
        $this->assertStringContainsString('table=tl_page', $default);
        $this->assertStringContainsString('id=17', $default);
        $this->assertStringContainsString('act=edit', $default);
    }

    /** The required backend parameters of the operation survive the switch. */
    public function testTheEditOperationIsPreserved(): void
    {
        $english = $this->urls->forLanguage('tl_page_translation', 501, 'en', 1, ['act' => 'edit']);

        $this->assertStringContainsString('table=tl_page_translation', $english);
        $this->assertStringContainsString('id=501', $english);
        $this->assertStringContainsString('act=edit', $english);
    }

    /** A retained legacy parameter is never written back into a URL. */
    public function testTheLegacyParameterIsNeverGenerated(): void
    {
        foreach (BackendLanguageContext::LEGACY_PARAMETERS as $legacy) {
            $url = $this->urls->build(['table' => 'tl_page', 'id' => 17, $legacy => 'en']);

            $this->assertStringNotContainsString($legacy, $url, $legacy.' must not be generated.');
        }
    }

    /** Empty values never become empty query parameters. */
    public function testEmptyParametersAreDropped(): void
    {
        $url = $this->urls->build(['table' => 'tl_page', 'id' => 17, 'act' => '', 'pid' => null]);

        $this->assertStringNotContainsString('act=', $url);
        $this->assertStringNotContainsString('pid=', $url);
    }

    /** Any parameter set can be re-scoped to the current editing language. */
    public function testAnArbitraryParameterSetInheritsTheActiveScope(): void
    {
        $english = new BackendTranslationScope(
            'tl_page',
            17,
            'tl_page',
            17,
            1,
            'de',
            'en',
            11,
            BackendLanguageFallback::None,
        );

        $scoped = $this->urls->withScope(['do' => 'article', 'table' => 'tl_content', 'id' => 9], $english);

        $this->assertSame('en', $scoped[BackendLanguageContext::LANGUAGE_PARAMETER]);
        $this->assertSame(1, $scoped[BackendLanguageContext::ROOT_PARAMETER]);
        $this->assertSame('tl_content', $scoped['table']);
        $this->assertSame(9, $scoped['id']);
    }

    /** Returning to the source language strips any inherited context. */
    public function testTheDefaultScopeStripsInheritedLanguageParameters(): void
    {
        $default = BackendTranslationScope::defaultLanguageScope(
            'tl_page',
            17,
            'tl_page',
            17,
            1,
            'de',
            BackendLanguageFallback::NotRequested,
        );

        $scoped = $this->urls->withScope(
            [
                'do' => 'page',
                BackendLanguageContext::LANGUAGE_PARAMETER => 'en',
                BackendLanguageContext::ROOT_PARAMETER => 1,
                'create_translation' => 'en',
            ],
            $default,
        );

        $this->assertArrayNotHasKey(BackendLanguageContext::LANGUAGE_PARAMETER, $scoped);
        $this->assertArrayNotHasKey(BackendLanguageContext::ROOT_PARAMETER, $scoped);
        $this->assertArrayNotHasKey('create_translation', $scoped);
        $this->assertSame('page', $scoped['do']);
    }

    /** A requested code is normalised before it ever reaches a URL. */
    public function testGeneratedLanguageValuesAreCanonical(): void
    {
        $this->assertStringContainsString(
            'contao_multilingual_pagetree_lang=pt_br',
            $this->urls->forLanguage('tl_page', 17, 'PT-BR', 1),
        );
    }
}
