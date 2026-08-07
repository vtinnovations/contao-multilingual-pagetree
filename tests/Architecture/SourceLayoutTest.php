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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural rules for the registration path.
 *
 * The point is not secrecy through naming: the code is readable and is meant to
 * be auditable by the product owner. The point is that the responsibilities stay
 * distributed across the bundle's normal architecture, so no single directory
 * describes the whole flow and no single removal disables every gate.
 */
final class SourceLayoutTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $root = realpath(self::ROOT) ?: self::ROOT;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            // Both sides are resolved, so the prefix that is stripped is really
            // the prefix that is there. Getting this wrong silently turned the
            // path-scoped rules below into assertions that never ran.
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = str_replace('\\', '/', substr((string) $file->getRealPath(), strlen($root) + 1));
            }
        }

        sort($files);

        return $files;
    }

    /** No directory or namespace advertises the subsystem. */
    public function testNoRevealingDirectoryExists(): void
    {
        foreach ($this->sourceFiles() as $file) {
            self::assertDoesNotMatchRegularExpression(
                '~(^|/)(Licensing|License|Licence|Protection|AntiTamper|DRM|VtOne|VTone)(/|$)~',
                $file,
                $file.' reintroduces an obvious subsystem directory.',
            );
        }

        self::assertDirectoryDoesNotExist(self::ROOT.'/src/Licensing');
    }

    /** No private symbol announces itself either. */
    public function testNoRevealingClassNameExists(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $class = basename($file, '.php');

            self::assertDoesNotMatchRegularExpression(
                '~^(License|Licence)(Manager|Validator|Service|Repository|UpdaterController|StateStore|IntegrityService)$~',
                $class,
                $file,
            );
            self::assertNotContains($class, ['TamperDetector', 'AntiTamper', 'ExpectedMd5', 'ChecksumGuard', 'VtoneLogger', 'VtOneClient'], $file);
        }
    }

    /** No stale reference to the migrated structure remains anywhere. */
    public function testNoStaleReferencesRemain(): void
    {
        $haystacks = array_merge(
            $this->sourceFiles(),
            ['src/Resources/config/services.yaml', 'src/Resources/config/routes.yaml', 'tools/static-installation-audit.js'],
        );

        foreach ($haystacks as $file) {
            $contents = (string) file_get_contents(self::ROOT.'/'.$file);

            foreach (['\\Licensing\\', 'LicenseUpdaterController', 'LicenseStateRepositoryInterface', 'DatabaseReplayStore'] as $stale) {
                self::assertStringNotContainsString($stale, $contents, $file.' still references '.$stale.'.');
            }
        }
    }

    /**
     * The sensitive responsibilities live in different architectural seams, and
     * no single one of them contains the complete flow.
     *
     * @dataProvider responsibilities
     */
    public function testResponsibilitiesAreDistributed(string $file, string $marker): void
    {
        $contents = (string) file_get_contents(self::ROOT.'/'.$file);

        self::assertStringContainsString($marker, $contents, $file.' no longer holds its responsibility.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function responsibilities(): iterable
    {
        yield 'fixed destinations' => ['src/Distribution/ChannelAddress.php', 'https://'];
        yield 'pinned material' => ['src/Support/PinnedMaterial.php', 'KEYS'];
        yield 'signature verification' => ['src/Support/DetachedSignature.php', 'sodium_crypto_sign_verify_detached'];
        yield 'byte digest tripwire' => ['src/Packaging/PackageReader.php', 'md5('];
        yield 'host policy' => ['src/Helper/CanonicalHost.php', 'hash_equals'];
        yield 'inbound authentication' => ['src/Security/ChannelRequestVerifier.php', "hash('sha256', \$rawBody)"];
        yield 'persistence' => ['src/Storage/FilesystemPackageStore.php', 'rename('];
        yield 'replay ledger' => ['src/Storage/DatabaseRequestLedger.php', 'GET_LOCK'];
        yield 'entitlement decision' => ['src/Security/CapabilityPolicy.php', 'CapabilityDecision'];
    }

    /**
     * The classic all-in-one file must not reappear: nothing may combine the
     * destinations, the key material, the digest check and the storage write.
     */
    public function testNoFileConcentratesTheWholeFlow(): void
    {
        $markers = ['https://', 'sodium_crypto_sign_verify_detached', 'md5(', 'rename(', 'GET_LOCK'];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents(self::ROOT.'/'.$file);
            $found = array_filter($markers, static fn (string $marker): bool => str_contains($contents, $marker));

            self::assertLessThan(3, count($found), $file.' concentrates too much of the flow: '.implode(', ', $found));
        }
    }

    /**
     * Each protected operation carries its own server-side check, so removing
     * one service, listener or flag cannot unlock everything.
     *
     * @dataProvider gates
     */
    public function testEveryProtectedOperationChecksAtItsOwnBoundary(string $file, string $capability): void
    {
        $contents = (string) file_get_contents(self::ROOT.'/'.$file);

        self::assertStringContainsString('Capability::'.$capability, $contents, $file.' lost its server-side gate.');
        self::assertStringContainsString('allows(', $contents, $file.' must ask the policy, not a cached flag.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function gates(): iterable
    {
        yield 'translation creation' => ['src/Backend/LanguageTabs.php', 'TranslationEditing'];
        yield 'review marking' => ['src/Review/TranslationReviewMarker.php', 'TranslationReview'];
        yield 'free content mode' => ['src/Content/ModeSwitchGuard.php', 'FreeContentMode'];
        yield 'free content import' => ['src/Content/ConnectedToFreeImporter.php', 'FreeContentMode'];
        yield 'integrity repair' => ['src/Integrity/IntegrityRepairExecutor.php', 'IntegrityRepair'];
        yield 'cascade execution' => ['src/Integrity/CascadeCleanup.php', 'IntegrityRepair'];
    }

    /** Visitor-facing behaviour is never gated: a problem must not take a site down. */
    public function testPublicRenderingIsNeverGated(): void
    {
        foreach (['src/Switcher/LanguageSwitcherBuilder.php', 'src/Metadata/LanguageMetadataBuilder.php', 'src/Routing/MultilingualPagetreeRouteProviderDecorator.php', 'src/Controller/FrontendModule/LanguageSwitcherController.php'] as $file) {
            self::assertStringNotContainsString('CapabilityPolicy', (string) file_get_contents(self::ROOT.'/'.$file), $file);
        }
    }

    /** The updater edge cannot write files or build code from request data. */
    public function testThePublicEdgeCannotWriteAnything(): void
    {
        $contents = (string) file_get_contents(self::ROOT.'/src/Controller/ChannelUpdateController.php');

        foreach (['eval(', 'file_put_contents', 'fopen(', 'unlink(', 'exec(', 'system(', 'unserialize('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $contents);
        }
    }

    /** Nothing anywhere in the bundle may execute or generate code. */
    public function testNoDynamicExecutionExistsAnywhere(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents(self::ROOT.'/'.$file);

            foreach (['eval(', 'create_function(', 'shell_exec(', 'proc_open(', 'passthru('] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $contents, $file.' uses '.$forbidden);
            }
        }
    }

    /**
     * The registration path never deserialises anything.
     *
     * One documented, class-free `unserialize()` exists elsewhere in the bundle
     * for legacy Contao column values; it must stay out of this path.
     */
    public function testTheRegistrationPathNeverDeserialises(): void
    {
        foreach ($this->sourceFiles() as $file) {
            if (1 !== preg_match('~^src/(Distribution|Packaging|Storage|Support|Security)/~', $file)) {
                continue;
            }

            self::assertStringNotContainsString('unserialize(', (string) file_get_contents(self::ROOT.'/'.$file), $file);
        }
    }

    /** TLS verification is never turned off, anywhere. */
    public function testTlsVerificationIsNeverDisabled(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents(self::ROOT.'/'.$file);

            self::assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER => false', $contents, $file);
            self::assertStringNotContainsString('CURLOPT_SSL_VERIFYHOST => 0', $contents, $file);
            self::assertStringNotContainsString('CURLOPT_FOLLOWLOCATION => true', $contents, $file);
        }
    }
}
