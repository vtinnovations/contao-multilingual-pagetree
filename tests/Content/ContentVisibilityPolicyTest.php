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
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentVisibilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;

class ContentVisibilityPolicyTest extends TestCase
{
    /** Requirements 17 and 52: the default language renders the source tree. */
    public function testTheDefaultLanguageRendersSourceRecordsOnly(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isRenderable(ContentOwnership::source(), 'en', true, ContentTranslationMode::Connected));
        $this->assertFalse($policy->isRenderable(ContentOwnership::free('de', 1), 'en', true, ContentTranslationMode::Connected));
    }

    /** Requirements 17 and 36: connected mode renders the source tree. */
    public function testConnectedModeRendersSourceRecordsOnly(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isRenderable(ContentOwnership::source(), 'de', false, ContentTranslationMode::Connected));
        $this->assertFalse($policy->isRenderable(ContentOwnership::free('de', 1), 'de', false, ContentTranslationMode::Connected));
    }

    /** Requirements 34, 35 and 46: free mode renders free records only. */
    public function testFreeModeRendersOnlyItsOwnRecordsAndNeverFallsBack(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isRenderable(ContentOwnership::free('de', 1), 'de', false, ContentTranslationMode::Free));
        $this->assertFalse(
            $policy->isRenderable(ContentOwnership::source(), 'de', false, ContentTranslationMode::Free),
            'Free mode never falls back to the source structure, not even when empty.',
        );
    }

    /** Requirement 51: one free language never sees another one. */
    public function testFreeRecordsOfAnotherLanguageAreNeverRendered(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->isRenderable(ContentOwnership::free('de', 1), 'fr', false, ContentTranslationMode::Free));
        $this->assertTrue($policy->isRenderable(ContentOwnership::free('fr', 1), 'fr', false, ContentTranslationMode::Free));
    }

    /** Requirements 53 and 127: free records never cross root sites. */
    public function testFreeRecordsOfAnotherRootSiteAreNeverRendered(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->isRenderable(ContentOwnership::free('de', 2), 'de', false, ContentTranslationMode::Free, 1));
        $this->assertTrue($policy->isRenderable(ContentOwnership::free('de', 1), 'de', false, ContentTranslationMode::Free, 1));
    }

    public function testLanguageComparisonIgnoresSeparatorAndCase(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isRenderable(ContentOwnership::free('de_CH', 1), 'de-ch', false, ContentTranslationMode::Free));
    }

    /** Requirements 63 and 116: only connected source records receive overlays. */
    public function testOnlyConnectedSourceRecordsUseFieldStateOverlays(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->usesConnectedOverlay(ContentOwnership::source(), false, ContentTranslationMode::Connected));
        $this->assertFalse($policy->usesConnectedOverlay(ContentOwnership::source(), true, ContentTranslationMode::Connected));
        $this->assertFalse($policy->usesConnectedOverlay(ContentOwnership::free('de', 1), false, ContentTranslationMode::Free));
        $this->assertFalse($policy->usesConnectedOverlay(ContentOwnership::source(), false, ContentTranslationMode::Free));
    }

    /** Requirement 57: the three record kinds stay distinguishable. */
    public function testOwnershipIsReadSafelyFromRecords(): void
    {
        $this->assertTrue(ContentOwnership::fromRecord(['id' => 1])->isSource());
        $this->assertTrue(ContentOwnership::fromRecord(['languageFlowLanguage' => ''])->isSource());
        $this->assertTrue(ContentOwnership::fromRecord(['languageFlowLanguage' => 'not a language'])->isSource());
        $this->assertTrue(ContentOwnership::fromRecord(['languageFlowLanguage' => ['de']])->isSource());

        $free = ContentOwnership::fromRecord(['languageFlowLanguage' => 'de', 'languageFlowRoot' => '7']);

        $this->assertTrue($free->isFree());
        $this->assertSame('de', $free->language);
        $this->assertSame(7, $free->rootPageId);
        $this->assertSame(['languageFlowLanguage' => 'de', 'languageFlowRoot' => 7], $free->toRow());
    }

    private function policy(): ContentVisibilityPolicy
    {
        return new ContentVisibilityPolicy(new CanonicalUrlPolicy());
    }
}
