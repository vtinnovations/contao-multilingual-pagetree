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
use Vtinnovations\ContaoMultilingualPagetree\Schema\BundleSchema;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlMessages;
use Vtinnovations\ContaoMultilingualPagetree\Url\ProtocolMode;

/**
 * Static wiring of the language URL architecture: DCA, schema, services,
 * translations and the single-resolver rule.
 *
 * These assertions replace the parts of a runtime check that cannot be executed
 * without a database - they prove that the field names, service definitions and
 * translation keys of the new architecture agree with each other.
 */
class LanguageUrlArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** @var list<string> */
    private const FIELDS = ['urlProtocol', 'urlDomain', 'urlEntryPoint'];

    private function read(string $path): string
    {
        $file = self::ROOT.'/'.$path;

        $this->assertFileExists($file, $path.' is part of the release.');

        return (string) file_get_contents($file);
    }

    public function testTheDcaDeclaresTheLanguageUrlLegendAndFields(): void
    {
        $dca = $this->read('contao/dca/tl_inline_language.php');

        // The legend sits between the language settings and page availability.
        foreach ([
            '{language_legend}', 'language', 'label', 'flag', 'language_selector_config',
            '{url_legend}', 'urlProtocol', 'urlDomain', 'urlEntryPoint',
            '{availability_legend}', 'pageAvailabilityMode', 'contentFallbackMode', 'contentTranslationMode',
            '{publish_legend}', 'published',
        ] as $palettePart) {
            $this->assertStringContainsString($palettePart, $dca);
        }

        foreach (self::FIELDS as $field) {
            $this->assertStringContainsString("'".$field."' => [", $dca, $field.' must be a DCA field.');
        }

        // Native DCA widgets only; no custom form.
        $this->assertStringContainsString("'inputType' => 'select'", $dca);
        $this->assertStringContainsString("'inputType' => 'text'", $dca);
    }

    /** Every URL field validates server side through the central services. */
    public function testEveryUrlFieldHasAServerSideSaveCallback(): void
    {
        $dca = $this->read('contao/dca/tl_inline_language.php');

        foreach (['validateProtocol', 'validateDomain', 'validateEntryPoint', 'validatePublished'] as $callback) {
            $this->assertStringContainsString(
                'LanguageUrlDca::class, \''.$callback.'\'',
                $dca,
                $callback.' must be wired as a DCA save callback.',
            );
        }

        // The fields stay behind Contao's own permission handling.
        foreach (self::FIELDS as $field) {
            $this->assertMatchesRegularExpression(
                "/'".$field."' => \[.*?'exclude'\s*=> true/s",
                $dca,
                $field.' must be permission controlled.',
            );
        }
    }

    /** The DCA callbacks delegate authorisation to the existing resolver. */
    public function testTheDcaCallbacksEnforceTheExistingPermissionResolver(): void
    {
        $callbacks = $this->read('src/Backend/LanguageUrlDca.php');

        $this->assertStringContainsString('SiteLanguageDca $scope', $callbacks);
        $this->assertSame(
            4,
            substr_count($callbacks, '$this->scope->assertRecordWrite('),
            'Every save callback must assert write access before it stores anything.',
        );
    }

    /** The entry-point callback persists the canonical scalar and nothing else. */
    public function testEntryPointCallbackReturnsTheNormalisedScalar(): void
    {
        $callbacks = $this->read('src/Backend/LanguageUrlDca.php');

        $body = $this->methodBody($callbacks, 'validateEntryPoint', 'validatePublished');
        $this->assertStringContainsString('$entryPoint = $this->entryPoints->normalize($value);', $body);
        $this->assertStringContainsString("['urlEntryPoint' => \$entryPoint]", $body);
        $this->assertStringContainsString('return $entryPoint;', $body);

        // Format and collision exceptions retain their own translated reason;
        // the callback must not relabel either as a generic entry-point error.
        $this->assertStringNotContainsString('catch (InvalidLanguageUrlException', $callbacks);
    }

    public function testTheStoredProtocolValuesAreStable(): void
    {
        $this->assertSame(['', 'https', 'http'], ProtocolMode::storedValues());
        $this->assertSame(ProtocolMode::Inherit, ProtocolMode::fromValue('nonsense'));
        $this->assertSame(ProtocolMode::Inherit, ProtocolMode::fromValue(null));
        $this->assertSame(ProtocolMode::Https, ProtocolMode::fromValue('HTTPS'));
        $this->assertNull(ProtocolMode::Inherit->scheme());
    }

    /** The migration, DCA and schema agree on the persisted column names. */
    public function testMigrationDcaAndSchemaUseTheSameColumnNames(): void
    {
        $dca = $this->read('contao/dca/tl_inline_language.php');
        $migration = $this->read('src/Migration/LanguageUrlMigration.php');
        $resolver = $this->read('src/Url/LanguageUrlResolver.php');

        foreach (self::FIELDS as $field) {
            $this->assertStringContainsString($field, $dca, $field);
            $this->assertStringContainsString("'".$field."'", $migration, $field);
            $this->assertStringContainsString($field, $resolver, $field);
        }

        $indexes = array_column(BundleSchema::namedIndexes(), 'columns', 'name');

        $this->assertSame(['pid', 'urlDomain', 'urlEntryPoint'], $indexes['clfmp_lang_url']);
        $this->assertSame(['urlDomain'], $indexes['clfmp_lang_host']);
    }

    /** Exactly one resolver builds language URL mappings. */
    public function testOnlyOneCanonicalLanguageUrlResolverExists(): void
    {
        $resolvers = glob(self::ROOT.'/src/Url/*Resolver.php') ?: [];
        $names = array_map(static fn (string $file): string => basename($file, '.php'), $resolvers);

        sort($names);

        $this->assertSame(['IncomingLanguageResolver', 'LanguageUrlResolver'], $names);
    }

    /** Exactly one code path decides the language of an incoming request. */
    public function testOnlyOneIncomingLanguageResolutionPathExists(): void
    {
        $helper = $this->read('src/Helper/LanguageHelper.php');
        $policy = $this->read('src/Routing/CanonicalUrlPolicy.php');

        $this->assertStringContainsString('$this->incomingLanguages->resolve(', $helper);
        $this->assertStringNotContainsString(
            'public function resolveLanguage(',
            $policy,
            'The superseded language resolution must not survive next to the central one.',
        );

        $matches = [];

        foreach ($this->sourceFiles() as $file) {
            if (str_contains((string) file_get_contents($file), 'function getActiveLanguage(')) {
                $matches[] = basename($file);
            }
        }

        $this->assertSame(['LanguageHelper.php'], $matches);
    }

    /** No global, root-agnostic language-domain map exists. */
    public function testNoGlobalCrossRootMappingCacheExists(): void
    {
        $set = $this->read('src/Url/LanguageUrlMappingSet.php');
        $resolver = $this->read('src/Url/LanguageUrlResolver.php');

        $this->assertStringContainsString('public readonly int $rootId', $set);
        $this->assertStringContainsString('array<int, LanguageUrlMappingSet> */', $resolver);
        $this->assertStringContainsString('public function reset(): void', $resolver);
    }

    /** No posted or browser-supplied hostname ever becomes authoritative. */
    public function testTheRequestHostIsOnlyEverCompared(): void
    {
        $resolver = $this->read('src/Url/LanguageUrlResolver.php');
        $set = $this->read('src/Url/LanguageUrlMappingSet.php');

        // A request host is compared, never stored or turned into a mapping.
        $this->assertStringContainsString('hash_equals($mapping->effectiveHostname, strtolower($host))', $set);
        $this->assertStringNotContainsString('$_SERVER', $resolver);
        $this->assertStringNotContainsString('$_POST', $resolver);
        $this->assertStringNotContainsString('$_GET', $resolver);

        foreach ($this->sourceFiles(self::ROOT.'/src/Url') as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('HTTP_HOST', $contents, basename($file));
            $this->assertStringNotContainsString('setTrustedHosts', $contents, basename($file));
        }
    }

    /** The service container knows every new class, and none that was removed. */
    public function testTheServiceDefinitionsMatchTheSources(): void
    {
        $services = $this->read('src/Resources/config/services.yaml');

        foreach ([
            'Url\LanguageDomainNormalizer',
            'Url\EntryPointNormalizer',
            'Url\LanguageUrlResolver',
            'Url\IncomingLanguageResolver',
            'Url\LanguageUrlCollisionValidator',
            'Backend\LanguageUrlDca',
            'Migration\LanguageUrlMigration',
        ] as $service) {
            $this->assertStringContainsString(
                'Vtinnovations\ContaoMultilingualPagetree\\'.$service.':',
                $services,
                $service.' must be a registered service.',
            );

            $path = self::ROOT.'/src/'.str_replace('\\', '/', $service).'.php';
            $this->assertFileExists($path, $service.' must exist as a class.');
        }

        // Value objects and enums must stay excluded from service discovery.
        foreach (['Url/LanguageUrlMapping.php', 'Url/LanguageUrlMappingSet.php', 'Url/ProtocolMode.php', 'Url/LanguageUrlMessages.php', 'Url/InvalidLanguageUrlException.php'] as $excluded) {
            $this->assertStringContainsString($excluded, $services, $excluded.' must be excluded from autowiring.');
        }
    }

    /** English and German carry every field label and validation message. */
    public function testEnglishAndGermanTranslationsAreComplete(): void
    {
        foreach (['en', 'de'] as $language) {
            $fields = $this->read('contao/languages/'.$language.'/tl_inline_language.php');

            $this->assertStringContainsString("['url_legend']", $fields, $language);

            foreach (self::FIELDS as $field) {
                $this->assertStringContainsString("['".$field."']", $fields, $language.'/'.$field);
            }

            $this->assertStringContainsString("['urlProtocols']", $fields, $language);
            $this->assertStringContainsString("'https' => ", $fields, $language);
            $this->assertStringContainsString("'http' => ", $fields, $language);

            $defaults = $this->read('contao/languages/'.$language.'/default.php');

            $this->assertStringContainsString("['".LanguageUrlMessages::GROUP."']", $defaults, $language);

            foreach (array_keys(LanguageUrlMessages::keys()) as $key) {
                $this->assertStringContainsString("'".$key."' => ", $defaults, $language.'/'.$key);
            }

            $this->assertStringContainsString('contaoMultilingualPagetreeLanguageUrlMigration', $defaults, $language);
            $this->assertStringContainsString('contaoMultilingualPagetreeLanguageUrlMigrated', $defaults, $language);
        }
    }

    /** The documented help texts are the ones the fields actually carry. */
    public function testTheDocumentedHelpTextsArePresent(): void
    {
        $english = $this->read('contao/languages/en/tl_inline_language.php');
        $german = $this->read('contao/languages/de/tl_inline_language.php');

        $this->assertStringContainsString('Select a fixed protocol or inherit the protocol configured for the website root.', $english);
        $this->assertStringContainsString('Optional. Leave empty to use the website root domain. Enter only a hostname, for example www.example.de.', $english);
        $this->assertStringContainsString('Optional language path prefix, for example /de. Use / for the domain root.', $english);

        $this->assertStringContainsString('Wählen Sie ein festes Protokoll oder übernehmen Sie das Protokoll der Website-Wurzel.', $german);
        $this->assertStringContainsString('Optional. Leer lassen, um die Domain der Website-Wurzel zu verwenden. Geben Sie nur einen Hostnamen ein, z. B. www.example.de.', $german);
        $this->assertStringContainsString('Optionaler Sprachpfad, z. B. /de. Verwenden Sie / für das Domain-Stammverzeichnis.', $german);
    }

    /** Both user guides document the new behaviour, German remains the default. */
    public function testTheUserDocumentationCoversTheNewBehaviour(): void
    {
        $german = $this->read('docs/USER-GUIDE.de.md');
        $english = $this->read('docs/USER-GUIDE.en.md');

        foreach (['www.xyz.de', '/de', 'Einstiegspfad'] as $needle) {
            $this->assertStringContainsString($needle, $german, $needle);
        }

        foreach (['www.xyz.de', '/de', 'Entry point'] as $needle) {
            $this->assertStringContainsString($needle, $english, $needle);
        }

        // Licensing internals stay out of the public documentation.
        $public = [$german, $english];

        foreach (['README.md', 'README.en.md', 'docs/RUNBOOK.de.md', 'docs/RUNBOOK.en.md', 'docs/PRODUCT-REGISTRATION.de.md', 'docs/PRODUCT-REGISTRATION.en.md'] as $path) {
            $public[] = $this->read($path);
        }

        foreach ($public as $document) {
            foreach (['sodium_crypto_sign', 'Ed25519', 'PinnedMaterial', 'private key', 'signing key'] as $secret) {
                $this->assertStringNotContainsString($secret, $document, $secret);
            }
        }
    }

    /** The licensing gate keeps its own root-scoped semantics. */
    public function testLicensingRemainsRootScoped(): void
    {
        $scope = $this->read('src/Metadata/RootScope.php');
        $registry = $this->read('src/Metadata/RootDomainRegistry.php');

        $this->assertStringContainsString('public function select(int $rootId, string $domain): void', $scope);
        $this->assertStringContainsString('Resolves only the primary domain persisted on an exact Contao site root.', $registry);

        // No language hostname is ever used as a licence domain.
        foreach ([$scope, $registry, $this->read('src/Security/CapabilityPolicy.php')] as $contents) {
            $this->assertStringNotContainsString('LanguageUrlMapping', $contents);
            $this->assertStringNotContainsString('urlDomain', $contents);
        }
    }

    /** Pruned code must leave nothing behind. */
    public function testPrunedCodeIsFullyRemoved(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('withoutLanguageQuery', $contents, basename($file));
            $this->assertStringNotContainsString('function getUrlPrefix(', $contents, basename($file));
        }
    }

    /**
     * Contao must be able to find the root from a language hostname alone.
     *
     * Contao 5.3's active RouteProvider resolves `/` from `tl_page.dns` before
     * this decorator sees the collection. The decorator must bootstrap the
     * owning root itself when that collection is empty; getRootPageFromUrl is
     * a static Frontend helper and is not a hook.
     */
    public function testALanguageHostnameCanResolveTheWebsiteRoot(): void
    {
        $provider = $this->read('src/Routing/MultilingualPagetreeRouteProviderDecorator.php');

        $this->assertStringContainsString('$this->bootstrapLanguageDomainRoot($request, $collection)', $provider);
        $this->assertStringContainsString('$this->urlResolver->rootForLanguageHost($host)', $provider);
        $this->assertStringContainsString("'tl_page.'.\$rootPageId.'.root'", $provider);
        $this->assertStringContainsString('$collection->add($routeName, $route)', $provider);
        $this->assertStringContainsString('public function rootForLanguageHost(string $host): ?int', $this->read('src/Url/LanguageUrlResolver.php'));

        $this->assertFileDoesNotExist(self::ROOT.'/src/EventListener/LanguageRootPageListener.php');
        $this->assertStringNotContainsString('getRootPageFromUrl', $this->read('src/Resources/config/services.yaml'));
    }

    /** The host lookup never widens beyond an exact persisted hostname. */
    public function testTheHostLookupStaysExact(): void
    {
        $resolver = $this->read('src/Url/LanguageUrlResolver.php');
        $body = $this->methodBody($resolver, 'rootForLanguageHost', 'projectMapping');

        $this->assertStringContainsString('hash_equals($mapping->effectiveHostname, $host)', $body);
        $this->assertStringContainsString('null !== $mapping->configuredDomain', $body);
        $this->assertStringContainsString('$this->mappings($rootId)->published()', $body);

        // A root's own domain is left to Contao, and an ambiguous host refused.
        $this->assertStringContainsString('hash_equals($primary, $host)', $body);
        $this->assertStringContainsString('1 === count($owners)', $body);

        foreach (['fnmatch', 'str_ends_with($host', 'preg_match', 'LIKE'] as $widening) {
            $this->assertStringNotContainsString($widening, $body, 'The host comparison must stay exact.');
        }
    }

    /** Exactly one place derives a language-code path for a URL. */
    public function testOnlyOnePlaceDerivesALanguageCodePath(): void
    {
        $derivers = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, "normalizeLanguage(\$languageCode), '/')")
                || str_contains($contents, "return '' === \$code ? [] : [\$code];")
            ) {
                $derivers[] = basename($file);
            }
        }

        sort($derivers);

        $this->assertSame(['LanguageUrlResolver.php'], $derivers);

        // ...and both refuse to derive one for a language with its own domain.
        $this->assertStringContainsString(
            'elseif ($isDefault || null !== $configuredDomain) {',
            $this->read('src/Url/LanguageUrlResolver.php'),
        );
        $this->assertStringContainsString('$this->languageUrlResolver->forLanguage(', $this->read('src/Routing/MultilingualPagetreePageRegistryDecorator.php'));
    }

    /** The navigation template never builds a path of its own. */
    public function testTheLanguageNavigationTemplateIsPresentationOnly(): void
    {
        $template = $this->read('contao/templates/mod_language_switcher.html.twig');

        $this->assertStringContainsString('{{ lang.href }}', $template);

        foreach (['lang.language ~', "'/' ~", 'entryPoint', 'replace(', 'slice('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $template,
                'The template must receive a finished URL, never build one.',
            );
        }
    }

    /** Every live URL consumer is explicitly wired to the same resolver. */
    public function testRuntimeConsumersUseTheCanonicalResolverService(): void
    {
        $services = $this->read('src/Resources/config/services.yaml');
        $resolverReference = '$urlResolver: \'@Vtinnovations\\ContaoMultilingualPagetree\\Url\\LanguageUrlResolver\'';

        $this->assertGreaterThanOrEqual(6, substr_count($services, $resolverReference));
        $this->assertStringContainsString('$languageUrlResolver: \'@Vtinnovations\\ContaoMultilingualPagetree\\Url\\LanguageUrlResolver\'', $services);
        $this->assertStringContainsString('$entryPoints: \'@Vtinnovations\\ContaoMultilingualPagetree\\Url\\EntryPointNormalizer\'', $services);

        foreach ([
            'EventListener\\LanguageRequestListener',
            'Availability\\PageAvailabilityResolver',
            'Availability\\ResourceAvailabilityResolver',
            'Detail\\DetailTargetUrlResolver',
            'Metadata\\LanguageMetadataBuilder',
            'Routing\\MultilingualPagetreePageRegistryDecorator',
            'Routing\\MultilingualPagetreeRouteProviderDecorator',
        ] as $consumer) {
            $this->assertStringContainsString('Vtinnovations\\ContaoMultilingualPagetree\\'.$consumer.':', $services);
        }

        $this->assertStringContainsString('$mapping = $this->urlResolver->forLanguage(', $this->read('src/Availability/PageAvailabilityResolver.php'));
        $this->assertStringContainsString('$this->withTargetOrigin(', $this->read('src/Availability/ResourceAvailabilityResolver.php'));
        $this->assertStringContainsString('$mapping = $this->urlResolver?->forLanguage(', $this->read('src/Metadata/LanguageMetadataBuilder.php'));
    }

    public function testPageRegistryDecoratorConstructorMatchesNamedServiceArguments(): void
    {
        $decorator = $this->read('src/Routing/MultilingualPagetreePageRegistryDecorator.php');
        $services = $this->read('src/Resources/config/services.yaml');

        foreach ([
            'PageRegistry $inner',
            'ContaoFramework $framework',
            'EntryPointNormalizer $entryPoints',
            'LanguageUrlResolver $languageUrlResolver',
        ] as $parameter) {
            $this->assertStringContainsString($parameter, $decorator);
        }

        $this->assertStringContainsString('$this->entryPoints->isRoot(', $decorator);
        $this->assertStringContainsString('$this->languageUrlResolver->forLanguage(', $decorator);
        $this->assertSame(1, substr_count($services, 'Routing\\MultilingualPagetreePageRegistryDecorator:'));
    }

    /** The stale code prefix exists only as a redirect route on its own host. */
    public function testDomainRootLegacyPrefixIsRedirectOnly(): void
    {
        $provider = $this->read('src/Routing/MultilingualPagetreeRouteProviderDecorator.php');

        $this->assertStringContainsString('$mapping?->hasDomainRootEntryPoint()', $provider);
        $this->assertStringContainsString('.domain_root', $provider);
        $this->assertStringContainsString("'kind' => self::KIND_REDIRECT", $provider);
        $this->assertStringContainsString("'host' => \$host", $provider);
        $this->assertStringContainsString("self::KIND_REDIRECT === \$plan['kind']", $provider);
        $this->assertStringContainsString("isset(\$canonicalPaths[\$this->targetKey(\$host, \$path)])", $provider);
    }

    /** A missing mapping is recorded instead of silently deriving a code. */
    public function testTheLegacyFallbackIsObservable(): void
    {
        $availability = $this->read('src/Availability/PageAvailabilityResolver.php');

        $this->assertStringContainsString('$mapping = $this->urlResolver->forLanguage($rootPageId, $result->targetLanguage);', $availability);
        $this->assertStringContainsString('falling back to the language-code path', $availability);
    }

    private function methodBody(string $source, string $from, string $to): string
    {
        $start = strpos($source, 'function '.$from.'(');
        $end = strpos($source, 'function '.$to.'(');

        return false === $start || false === $end ? '' : substr($source, $start, $end - $start);
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(?string $directory = null): array
    {
        $directory ??= self::ROOT.'/src';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = (string) $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
