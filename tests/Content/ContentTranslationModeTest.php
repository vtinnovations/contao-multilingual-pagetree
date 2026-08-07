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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Content;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationModeResolver;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PageModelMockTrait;

class ContentTranslationModeTest extends TestCase
{
    use PageModelMockTrait;

    /** Requirements 1 and 2 */
    public function testBothModesAreAccepted(): void
    {
        $this->assertSame(ContentTranslationMode::Connected, ContentTranslationMode::fromValue('connected'));
        $this->assertSame(ContentTranslationMode::Free, ContentTranslationMode::fromValue('free'));
        $this->assertTrue(ContentTranslationMode::Free->isFree());
        $this->assertTrue(ContentTranslationMode::Connected->isConnected());
    }

    /**
     * Requirements 3 and 4: anything unusable is connected, never free.
     *
     * @dataProvider invalidValues
     */
    public function testInvalidAndMissingValuesResolveToConnected(mixed $value): void
    {
        $this->assertSame(ContentTranslationMode::Connected, ContentTranslationMode::fromValue($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'unknown' => ['independent'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'boolean' => [true];
        yield 'array' => [['free']];
    }

    public function testValuesAreCaseAndWhitespaceInsensitive(): void
    {
        $this->assertSame(ContentTranslationMode::Free, ContentTranslationMode::fromValue('  FREE '));
    }

    /** Requirements 5 and 10 */
    public function testTheDefaultLanguageAlwaysRendersTheSourceStructure(): void
    {
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default', 'free')->add(1, 'de', 'fallback', 'free');
        $resolver = $this->resolver($registry);

        $this->assertSame(ContentTranslationMode::Connected, $resolver->getModeForRoot(1, 'en'));
        $this->assertSame(ContentTranslationMode::Free, $resolver->getModeForRoot(1, 'de'));
    }

    /** Requirements 11 and 12 */
    public function testTargetLanguagesResolveToTheirConfiguredMode(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')
            ->add(1, 'de', 'fallback', 'connected')
            ->add(1, 'fr', 'fallback', 'free');
        $resolver = $this->resolver($registry);

        $this->assertSame(ContentTranslationMode::Connected, $resolver->getModeForRoot(1, 'de'));
        $this->assertSame(ContentTranslationMode::Free, $resolver->getModeForRoot(1, 'fr'));
    }

    /** Requirements 9, 13, 124, 125 and 126: modes never cross root sites. */
    public function testTheSameLanguageMayUseDifferentModesPerSite(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')->add(1, 'de', 'fallback', 'connected')
            ->add(2, 'en', 'default')->add(2, 'de', 'fallback', 'free');
        $resolver = $this->resolver($registry);

        $this->assertSame(ContentTranslationMode::Connected, $resolver->getModeForRoot(1, 'de'));
        $this->assertSame(ContentTranslationMode::Free, $resolver->getModeForRoot(2, 'de'));
    }

    public function testTheModeIsResolvedFromThePageRootSite(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')->add(1, 'de', 'fallback', 'connected')
            ->add(2, 'en', 'default')->add(2, 'de', 'fallback', 'free');
        $resolver = $this->resolver($registry);

        $this->assertSame(
            ContentTranslationMode::Connected,
            $resolver->getModeForPageLanguage($this->mockRegularPage(10, 1, 'about-us'), 'de'),
        );
        $this->assertSame(
            ContentTranslationMode::Free,
            $resolver->getModeForPageLanguage($this->mockRegularPage(20, 2, 'about-us'), 'de'),
        );
    }

    /** Requirement 16 */
    public function testAMissingConfigurationFailsSafelyToConnected(): void
    {
        $resolver = $this->resolver(new FakeSiteLanguageRegistry());

        $this->assertSame(ContentTranslationMode::Connected, $resolver->getModeForRoot(0, 'de'));
        $this->assertSame(ContentTranslationMode::Connected, $resolver->getModeForRoot(99, 'de'));
        $this->assertSame(ContentTranslationMode::Connected, $resolver->getModeForRoot(1, ''));
    }

    private function resolver(FakeSiteLanguageRegistry $registry): ContentTranslationModeResolver
    {
        return new ContentTranslationModeResolver($registry, new CanonicalUrlPolicy());
    }
}
