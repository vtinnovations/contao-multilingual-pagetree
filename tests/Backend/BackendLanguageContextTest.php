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
use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageFallback;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryBackendLanguageContext;

/**
 * Behaviour of the one backend translation-context resolver.
 *
 * These assertions replace a previous test that only checked that certain
 * strings existed in the source. A shape test cannot tell a resolved context
 * from an unresolved one, which is how a tab that never activated kept passing.
 */
class BackendLanguageContextTest extends TestCase
{
    private const ROOT = 1;
    private const OTHER_ROOT = 2;
    private const PAGE = 17;

    public function testWithoutAParameterTheDefaultLanguageIsActive(): void
    {
        $scope = $this->context()->scope('tl_page', self::PAGE);

        $this->assertTrue($scope->isDefaultLanguage());
        $this->assertNull($scope->activeLanguage);
        $this->assertSame('de', $scope->defaultLanguage);
        $this->assertSame('de', $scope->editingLanguage());
        $this->assertSame(self::ROOT, $scope->rootId);
        $this->assertSame(BackendLanguageFallback::NotRequested, $scope->fallbackReason);
        $this->assertFalse($scope->wasRefused(), 'Not asking for a language is not a refusal.');
    }

    /** The whole point: a valid English request must become the active context. */
    public function testAValidAdditionalLanguageBecomesActive(): void
    {
        $scope = $this->context()->request('en')->scope('tl_page', self::PAGE);

        $this->assertFalse($scope->isDefaultLanguage());
        $this->assertSame('en', $scope->activeLanguage);
        $this->assertSame('en', $scope->editingLanguage());
        $this->assertSame(11, $scope->activeLanguageId);
        $this->assertSame(BackendLanguageFallback::None, $scope->fallbackReason);
        $this->assertFalse($scope->wasRefused());
        $this->assertSame('tl_page_translation', $scope->translationTable());
        $this->assertSame(self::PAGE, $scope->sourceId);
    }

    /** Any spelling of the same language selects it and lights up its tab. */
    public function testLanguageCodesAreComparedInOneNormalisedForm(): void
    {
        foreach (['en', 'EN', 'En'] as $spelling) {
            $scope = $this->context()->request($spelling)->scope('tl_page', self::PAGE);

            $this->assertSame('en', $scope->activeLanguage, $spelling);
            $this->assertTrue($scope->isEditing('EN'), $spelling);
            $this->assertTrue($scope->isEditing('en'), $spelling);
            $this->assertFalse($scope->isEditing('de'), $spelling);
        }

        $regional = $this->regionalContext()->request('pt-BR')->scope('tl_page', self::PAGE);

        $this->assertSame('pt_br', $regional->activeLanguage);
        $this->assertTrue($regional->isEditing('pt-BR'), 'A hyphenated request must match an underscored record.');
        $this->assertTrue($regional->isEditing('pt_br'));
    }

    /**
     * @dataProvider refusals
     */
    public function testEveryRefusalKeepsTheDefaultLanguageAndRecordsWhy(
        InMemoryBackendLanguageContext $context,
        string $language,
        BackendLanguageFallback $expected,
    ): void {
        $scope = $context->request($language)->scope('tl_page', self::PAGE);

        $this->assertTrue($scope->isDefaultLanguage());
        $this->assertNull($scope->activeLanguage);
        $this->assertSame($expected, $scope->fallbackReason);
        $this->assertTrue($scope->wasRefused(), 'A requested but refused language must be reported.');
    }

    /**
     * @return iterable<string, array{InMemoryBackendLanguageContext, string, BackendLanguageFallback}>
     */
    public static function refusals(): iterable
    {
        yield 'malformed parameter' => [self::build(), 'not-a-language', BackendLanguageFallback::InvalidParameter];
        yield 'language of the root itself' => [self::build(), 'de', BackendLanguageFallback::IsDefaultLanguage];
        yield 'unconfigured language' => [self::build(), 'it', BackendLanguageFallback::NotConfigured];
        yield 'unpublished language' => [self::build(), 'fr', BackendLanguageFallback::NotPublished];
        yield 'language of another root' => [self::build(), 'es', BackendLanguageFallback::ForeignRoot];
        yield 'permission denied' => [self::build(permitted: false), 'en', BackendLanguageFallback::PermissionDenied];
        yield 'licence denied' => [self::build(licensed: false), 'en', BackendLanguageFallback::LicenceDenied];
        yield 'root without a domain' => [self::build(licensed: false, rootDomain: null), 'en', BackendLanguageFallback::RootDomainMissing];
    }

    /** A manipulated parameter can never reach another root's translations. */
    public function testAForeignRootLanguageIsRefusedAndDistinguishable(): void
    {
        $scope = $this->context()->request('es')->scope('tl_page', self::PAGE);

        $this->assertTrue($scope->isDefaultLanguage());
        $this->assertSame(BackendLanguageFallback::ForeignRoot, $scope->fallbackReason);
        $this->assertSame(self::ROOT, $scope->rootId, 'The scope stays on the root that owns the record.');
        $this->assertSame([], $scope->urlParameters(), 'A refused language contributes no URL context.');
    }

    /** A record that cannot be traced to a root never becomes translatable. */
    public function testAnUnknownRootRefusesEveryLanguage(): void
    {
        $scope = $this->context()->request('en')->scope('tl_page', 999);

        $this->assertSame(0, $scope->rootId);
        $this->assertTrue($scope->isDefaultLanguage());
        $this->assertSame(BackendLanguageFallback::UnknownRoot, $scope->fallbackReason);
    }

    /** A translation record is its own context, whatever the URL says. */
    public function testATranslationRecordCarriesItsOwnLanguage(): void
    {
        $scope = $this->context()->scope('tl_page_translation', 501);

        $this->assertTrue($scope->isOnTranslationTable());
        $this->assertSame('en', $scope->activeLanguage);
        $this->assertSame(self::PAGE, $scope->sourceId);
        $this->assertSame('tl_page', $scope->sourceTable);
        $this->assertSame(BackendLanguageFallback::None, $scope->fallbackReason);
    }

    /** Direct translation editing without a licence is refused, not redirected. */
    public function testATranslationRecordIsRefusedWithoutALicence(): void
    {
        $scope = self::build(licensed: false)->scope('tl_page_translation', 501);

        $this->assertTrue($scope->isDefaultLanguage());
        $this->assertTrue($scope->wasRefused());
        $this->assertTrue($scope->fallbackReason->isDenial());
        $this->assertSame(BackendLanguageFallback::LicenceDenied, $scope->fallbackReason);
    }

    /** The retained legacy parameter still selects a language, once. */
    public function testTheLegacyParameterIsAcceptedAsInputOnly(): void
    {
        $this->assertSame(['create_translation'], \Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageContext::LEGACY_PARAMETERS);
        $this->assertSame(
            'contao_multilingual_pagetree_lang',
            \Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageContext::LANGUAGE_PARAMETER,
        );

        // Whatever parameter carried it, the resolved context is the canonical
        // one and only the canonical parameter is ever written back out.
        $scope = $this->context()->request('en')->scope('tl_page', self::PAGE);

        $this->assertSame(
            [
                'contao_multilingual_pagetree_lang' => 'en',
                'contao_multilingual_pagetree_root' => self::ROOT,
            ],
            $scope->urlParameters(),
        );
    }

    /** The same request resolves to the identical object everywhere. */
    public function testTheScopeIsResolvedOncePerRecord(): void
    {
        $context = $this->context()->request('en');

        $this->assertSame($context->scope('tl_page', self::PAGE), $context->scope('tl_page', self::PAGE));
    }

    /** The legacy array shape keeps working for existing callers. */
    public function testTheLegacyArrayShapeStillDescribesTheSameScope(): void
    {
        $resolved = $this->context()->request('en')->resolve('tl_page', self::PAGE);

        $this->assertSame('tl_page', $resolved['table']);
        $this->assertSame(self::PAGE, $resolved['id']);
        $this->assertSame(self::ROOT, $resolved['rootId']);
        $this->assertSame('de', $resolved['defaultLanguage']);
        $this->assertSame('en', $resolved['activeLanguage']);

        $this->assertSame('default', $this->context()->resolve('tl_page', self::PAGE)['activeLanguage']);
    }

    /** The diagnostic payload carries categories only - never a secret. */
    public function testTheDiagnosticPayloadIsSafe(): void
    {
        $payload = $this->context()->request('en')->scope('tl_page', self::PAGE)->toDiagnosticArray();

        $this->assertSame(
            ['table', 'id', 'sourceTable', 'sourceId', 'rootId', 'defaultLanguage', 'activeLanguage', 'activeLanguageId', 'isDefault', 'fallbackReason', 'contentMode'],
            array_keys($payload),
        );

        $serialised = json_encode($payload);

        foreach (['rt', 'token', 'cookie', 'session', 'licence', 'license', 'key'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase('"'.$forbidden.'"', (string) $serialised);
        }
    }

    private function context(): InMemoryBackendLanguageContext
    {
        return self::build();
    }

    private function regionalContext(): InMemoryBackendLanguageContext
    {
        return new InMemoryBackendLanguageContext(
            [self::ROOT => [['id' => 14, 'language' => 'pt_br', 'published' => true]]],
            ['tl_page#'.self::PAGE => self::ROOT],
            [],
            null,
            true,
            true,
            'www.example.com',
            (new FakeSiteLanguageRegistry())->add(self::ROOT, 'de', 'default')->add(self::ROOT, 'pt_br', 'fallback'),
        );
    }

    private static function build(bool $permitted = true, bool $licensed = true, ?string $rootDomain = 'www.example.com'): InMemoryBackendLanguageContext
    {
        return new InMemoryBackendLanguageContext(
            [
                self::ROOT => [
                    ['id' => 11, 'language' => 'en', 'published' => true],
                    ['id' => 12, 'language' => 'fr', 'published' => false],
                ],
                self::OTHER_ROOT => [
                    ['id' => 21, 'language' => 'es', 'published' => true],
                ],
            ],
            [
                'tl_page#'.self::PAGE => self::ROOT,
                'tl_page#88' => self::OTHER_ROOT,
            ],
            ['tl_page_translation#501' => ['pid' => self::PAGE, 'language' => 'en']],
            null,
            $permitted,
            $licensed,
            $rootDomain,
            (new FakeSiteLanguageRegistry())
                ->add(self::ROOT, 'de', 'default')
                ->add(self::ROOT, 'en', 'fallback')
                ->add(self::OTHER_ROOT, 'de', 'default')
                ->add(self::OTHER_ROOT, 'es', 'fallback'),
        );
    }
}
