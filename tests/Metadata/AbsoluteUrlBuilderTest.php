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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\AbsoluteUrlBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PageModelMockTrait;

class AbsoluteUrlBuilderTest extends TestCase
{
    use PageModelMockTrait;

    public function testTheCurrentRequestProvidesSchemeAndHostWithoutAConfiguredDomain(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us');
        $request = Request::create('https://www.example.com/about-us');

        $this->assertSame(
            'https://www.example.com/about-us',
            (new AbsoluteUrlBuilder())->build($page, '/about-us', $request),
        );
    }

    /** Requirement 66: each root site advertises its own configured domain. */
    public function testAConfiguredRootDomainWins(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['domain' => 'example.de', 'rootUseSSL' => '1']);
        $request = Request::create('http://localhost/about-us');

        $this->assertSame(
            'https://example.de/about-us',
            (new AbsoluteUrlBuilder())->build($page, '/about-us', $request),
        );
    }

    public function testAConfiguredDomainWithoutSslUsesHttp(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['domain' => 'example.de', 'rootUseSSL' => '']);
        $request = Request::create('http://localhost/about-us');

        $this->assertSame(
            'http://example.de/about-us',
            (new AbsoluteUrlBuilder())->build($page, '/about-us', $request),
        );
    }

    public function testAMalformedDomainFallsBackToTheRequest(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['domain' => 'https://evil.example.com/path']);
        $request = Request::create('https://www.example.com/about-us');

        $this->assertSame(
            'https://www.example.com/about-us',
            (new AbsoluteUrlBuilder())->build($page, '/about-us', $request),
        );
    }

    public function testDuplicateSlashesAreCollapsed(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['domain' => 'example.de']);

        $this->assertSame(
            'http://example.de/de/about-us',
            (new AbsoluteUrlBuilder())->build($page, '//de//about-us'),
        );
    }

    public function testUrlSuffixesAndBasePathsArePreserved(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['domain' => 'example.de']);

        $this->assertSame(
            'http://example.de/shop/de/about-us.html',
            (new AbsoluteUrlBuilder())->build($page, '/shop/de/about-us.html'),
        );
    }

    public function testWithoutADomainAndWithoutARequestNoUrlIsInvented(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us');

        $this->assertNull((new AbsoluteUrlBuilder())->build($page, '/about-us'));
        $this->assertNull((new AbsoluteUrlBuilder())->build(null, '', Request::create('/')));
    }

    public function testPortsArePreserved(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['domain' => 'example.de:8443', 'rootUseSSL' => '1']);

        $this->assertSame(
            'https://example.de:8443/about-us',
            (new AbsoluteUrlBuilder())->build($page, '/about-us'),
        );
    }
}
