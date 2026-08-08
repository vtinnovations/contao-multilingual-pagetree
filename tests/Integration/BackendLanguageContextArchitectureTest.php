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
use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageContext;
use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageFallback;

/**
 * Structural guarantees of the backend translation context.
 *
 * The previous version of this file asserted that certain strings existed in
 * the source. That is why earlier repairs "passed" while the English tab still
 * never activated: a shape assertion cannot see a context that fails to
 * resolve. The behavioural rules now live in
 * {@see \Vtinnovations\ContaoMultilingualPagetree\Tests\Backend\BackendLanguageContextTest};
 * what remains here are the rules that are genuinely structural - that there is
 * exactly one of each thing.
 */
class BackendLanguageContextArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private function read(string $file): string
    {
        $path = self::ROOT.'/'.$file;

        $this->assertFileExists($path, $file.' is part of the release.');

        return (string) file_get_contents($path);
    }

    /** Exactly one class resolves the backend editing language. */
    public function testOnlyOneBackendLanguageContextResolverExists(): void
    {
        $resolvers = [];

        foreach ($this->sourceFiles() as $file) {
            if (str_contains((string) file_get_contents($file), 'function requestedLanguage(')) {
                $resolvers[] = basename($file);
            }
        }

        $this->assertSame(['BackendLanguageContext.php'], $resolvers);
    }

    /** Exactly one canonical query parameter is ever generated. */
    public function testOnlyTheCanonicalParameterIsGenerated(): void
    {
        $this->assertSame('contao_multilingual_pagetree_lang', BackendLanguageContext::LANGUAGE_PARAMETER);
        $this->assertSame('contao_multilingual_pagetree_root', BackendLanguageContext::ROOT_PARAMETER);
        $this->assertSame(['create_translation'], BackendLanguageContext::LEGACY_PARAMETERS);

        // Only the backend layer generates backend URLs; the frontend URL
        // mapping legitimately uses "languageId" as a value-object key.
        foreach ($this->sourceFiles(self::ROOT.'/src/Backend') as $file) {
            $contents = (string) file_get_contents($file);

            foreach (["'lang' =>", "'languageId' =>", "'targetLanguage' =>", "'translationLanguage' =>", "'create_translation' =>"] as $competing) {
                $this->assertStringNotContainsString(
                    $competing,
                    $contents,
                    basename($file).' generates a competing language parameter.',
                );
            }
        }

        // The retained legacy parameter is stripped in the one URL builder.
        $this->assertStringContainsString('unset($parameters[$legacy]);', $this->read('src/Backend/BackendUrlBuilder.php'));
    }

    /** Every backend URL that carries a language goes through one builder. */
    public function testTabUrlsAreBuiltByTheOneUrlBuilder(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        $this->assertStringContainsString('$this->urls->forLanguage(', $tabs);
        $this->assertStringContainsString('$this->urls->forDefaultLanguage(', $tabs);
        $this->assertStringNotContainsString(
            "router->generate('contao_backend'",
            $tabs,
            'Backend URLs must not be assembled next to the one builder.',
        );
    }

    /** The active tab is decided by the resolved scope, never by CSS or JS. */
    public function testTheActiveTabComesFromTheResolvedContext(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        $this->assertStringContainsString('$scope = $this->context->scope($table, (int) $dc->id);', $tabs);
        $this->assertStringContainsString('$scope->isEditing($code)', $tabs);
        $this->assertStringContainsString("'active' => \$isActive", $tabs);

        foreach (glob(self::ROOT.'/public/js/*.js') ?: [] as $script) {
            $contents = (string) file_get_contents($script);

            $this->assertStringNotContainsString('cmp-language-tab', $contents, basename($script));
            $this->assertStringNotContainsString('tl_tabs', $contents, basename($script));
        }
    }

    /** A refused language is reported, never silently reverted to the source. */
    public function testARefusedLanguageIsReportedInsteadOfBouncing(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        $this->assertStringContainsString('Message::addError($this->refusalMessage($scope))', $tabs);
        $this->assertStringContainsString('throw new AccessDeniedHttpException($this->refusalMessage($scope))', $tabs);

        // The former silent bounce redirected the source table back onto itself
        // through an untyped context array.
        $this->assertStringNotContainsString('$resolved[', $tabs);
    }

    /** Every fallback reason is translated in both shipped languages. */
    public function testEveryFallbackReasonIsTranslated(): void
    {
        foreach (['en', 'de'] as $language) {
            $defaults = $this->read('contao/languages/'.$language.'/default.php');

            $this->assertStringContainsString('contaoMultilingualPagetreeLanguageRefused', $defaults, $language);
            $this->assertStringContainsString("'default' => ", $defaults, $language);

            foreach (BackendLanguageFallback::cases() as $case) {
                if (in_array($case, [BackendLanguageFallback::None, BackendLanguageFallback::NotRequested], true)) {
                    continue;
                }

                $this->assertStringContainsString("'".$case->value."' => ", $defaults, $language.'/'.$case->value);
            }
        }
    }

    /** Permission and the licence gate are resolved centrally, exactly once. */
    public function testPermissionAndLicenceAreNotReimplemented(): void
    {
        $context = $this->read('src/Backend/BackendLanguageContext.php');
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        $this->assertStringContainsString('$this->permissions->canManage($rootId)', $context);
        $this->assertStringContainsString('Capability::TranslationEditing', $context);

        // The renderer asks the context; it never evaluates the gate itself.
        $this->assertStringNotContainsString('Capability::TranslationEditing', $tabs);
        $this->assertStringNotContainsString('CapabilityPolicy', $tabs);
        $this->assertStringContainsString('$this->context->mayEditTranslations()', $tabs);

        // The licence scope is selected by the resolver immediately before the
        // gate, not as a side effect of palette configuration.
        $this->assertStringContainsString('$scopeReason = $this->selectLicenceScope($rootId);', $context);
        $this->assertStringNotContainsString('selectLicenceScope', $tabs);
    }

    /** One root resolution exists for the backend; the frontend one is not reused. */
    public function testOnlyOneBackendRootResolutionExists(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');
        $context = $this->read('src/Backend/BackendLanguageContext.php');

        $this->assertStringNotContainsString('private function getRootId(', $tabs);
        $this->assertStringNotContainsString('getRootPageId()', $tabs);
        $this->assertStringNotContainsString(
            'getRootPageId()',
            $context,
            'The frontend page model must never decide a backend root.',
        );
        $this->assertStringContainsString('public function rootId(string $table, int $id): int', $context);
    }

    /** The record context is only resolved for actions that address a record. */
    public function testListActionsDoNotResolveARecordContext(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        $this->assertStringContainsString('if (!$this->isRecordAction($table)) {', $tabs);
        $this->assertStringContainsString("in_array(\$action, ['edit', 'show'], true)", $tabs);
        $this->assertStringContainsString('isSubmittedRecordAction($action, $formSubmit, $table)', $tabs);
        $this->assertStringContainsString('hash_equals($table, $formSubmit)', $tabs);
    }

    /** The licensing implementation is untouched by this repair. */
    public function testLicensingImplementationIsUnchanged(): void
    {
        $this->assertStringContainsString(
            'sodium_crypto_sign_verify_detached',
            $this->read('src/Support/DetachedSignature.php'),
        );
        $this->assertStringContainsString('KEYS', $this->read('src/Support/PinnedMaterial.php'));
        $this->assertStringContainsString('https://', $this->read('src/Distribution/ChannelAddress.php'));
        $this->assertStringContainsString(
            'public function select(int $rootId, string $domain): void',
            $this->read('src/Metadata/RootScope.php'),
        );
    }

    /** The frontend entry-point implementation stays intact. */
    public function testFrontendEntryPointImplementationRemainsPresent(): void
    {
        $this->assertStringContainsString('EntryPointNormalizer', $this->read('src/Url/LanguageUrlResolver.php'));
        $this->assertStringContainsString('urlEntryPoint', $this->read('contao/dca/tl_inline_language.php'));
        $this->assertStringContainsString('IncomingLanguageResolver', $this->read('src/Helper/LanguageHelper.php'));

        // The backend repair must not have reached into frontend routing.
        foreach ([
            'src/Url/LanguageUrlResolver.php',
            'src/Url/IncomingLanguageResolver.php',
            'src/Helper/LanguageHelper.php',
            'src/Routing/MultilingualPagetreeRouteProviderDecorator.php',
            'src/EventListener/LanguageRequestListener.php',
        ] as $frontend) {
            $this->assertStringNotContainsString('BackendLanguageContext', $this->read($frontend), $frontend);
        }
    }

    /** Connected and free content keep their separate paths. */
    public function testConnectedAndFreeContentRemainDistinct(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');
        $content = $this->read('src/Backend/ContentModeDca.php');

        $this->assertStringContainsString('$scope->contentMode->isFree()', $tabs);
        $this->assertStringContainsString('freeContentTarget(', $tabs);
        $this->assertStringContainsString('$scope->translationTable()', $tabs);
        $this->assertStringContainsString("ContentOwnership::FIELD_LANGUAGE.'=?'", $content);
        $this->assertStringContainsString("ContentOwnership::FIELD_ROOT.'=?'", $content);
    }

    /** The context service is request scoped and released between cycles. */
    public function testTheContextIsRequestScopedAndNeverSessionBased(): void
    {
        $services = $this->read('src/Resources/config/services.yaml');
        $context = $this->read('src/Backend/BackendLanguageContext.php');

        $this->assertMatchesRegularExpression(
            '/Backend\\\\BackendLanguageContext:\s*\n\s*public: true.*?kernel\.reset/s',
            $services,
        );
        $this->assertStringContainsString('Backend\BackendUrlBuilder:', $services);
        $this->assertStringContainsString('implements ResetInterface', $context);
        $this->assertStringNotContainsString(
            'Session',
            $context,
            'The selected language is explicit URL state, never session state.',
        );
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(?string $directory = null): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory ?? self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = (string) $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
