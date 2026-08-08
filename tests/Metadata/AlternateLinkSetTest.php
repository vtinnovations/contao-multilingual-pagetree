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
use Vtinnovations\ContaoMultilingualPagetree\Metadata\AlternateLinkSet;

class AlternateLinkSetTest extends TestCase
{
    public function testFirstEntryPerLanguageWins(): void
    {
        $set = new AlternateLinkSet();

        $this->assertTrue($set->add('de', 'https://example.com/de/seite'));
        $this->assertFalse($set->add('de', 'https://example.com/de/andere-seite'));
        $this->assertSame(['de' => 'https://example.com/de/seite'], $set->all());
    }

    /**
     * Requirement 58: equivalent URLs are only emitted once.
     *
     * @dataProvider equivalentUrls
     */
    public function testEquivalentUrlsAreDeduplicated(string $first, string $second): void
    {
        $set = new AlternateLinkSet();

        $this->assertTrue($set->add('en', $first));
        $this->assertFalse($set->add('de', $second), 'The second URL is a duplicate of the first.');
        $this->assertCount(1, $set->all());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentUrls(): iterable
    {
        yield 'trailing slash' => ['https://example.com/page', 'https://example.com/page/'];
        yield 'duplicate slashes' => ['https://example.com/page', 'https://example.com//page'];
        yield 'encoded' => ['https://example.com/über-uns', 'https://example.com/%C3%BCber-uns'];
        yield 'host case' => ['https://Example.com/page', 'https://example.com/page'];
        yield 'query order' => ['https://example.com/p?b=2&a=1', 'https://example.com/p?a=1&b=2'];
        yield 'empty query' => ['https://example.com/page', 'https://example.com/page?'];
    }

    public function testDifferentUrlsAreKept(): void
    {
        $set = new AlternateLinkSet();

        $this->assertTrue($set->add('en', 'https://example.com/about-us'));
        $this->assertTrue($set->add('de', 'https://example.com/de/ueber-uns'));
        $this->assertCount(2, $set->all());
    }

    public function testDifferentHostsAreKept(): void
    {
        $set = new AlternateLinkSet();

        $this->assertTrue($set->add('en', 'https://example.com/page'));
        $this->assertTrue($set->add('de', 'https://example.de/page'));
        $this->assertCount(2, $set->all());
    }

    public function testEmptyValuesAreRejected(): void
    {
        $set = new AlternateLinkSet();

        $this->assertFalse($set->add('', 'https://example.com/page'));
        $this->assertFalse($set->add('de', ''));
        $this->assertSame([], $set->all());
    }

    public function testContainsUrlUsesTheSameNormalisation(): void
    {
        $set = new AlternateLinkSet();
        $set->add('en', 'https://example.com/page');

        $this->assertTrue($set->containsUrl('https://example.com/page/'));
        $this->assertFalse($set->containsUrl('https://example.com/other'));
        $this->assertTrue($set->has('en'));
    }
}
