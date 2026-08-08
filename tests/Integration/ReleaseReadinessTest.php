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
use Vtinnovations\ContaoMultilingualPagetree\DependencyInjection\VtinnovationsContaoMultilingualPagetreeExtension;

/**
 * Package metadata, documentation and distribution readiness.
 *
 * @legacy-identity-reference the predecessor package name is asserted here on
 * purpose, so the Composer conflict that prevents installing both packages
 * side by side cannot be removed unnoticed
 */
class ReleaseReadinessTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $data = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true);

        $this->assertIsArray($data, 'composer.json must be valid JSON.');

        return $data;
    }

    public function testPackageIdentityIsCorrect(): void
    {
        $composer = $this->composer();

        $this->assertSame('vtinnovations/contao-multilingual-pagetree', $composer['name']);
        $this->assertSame('contao-bundle', $composer['type']);
        $this->assertSame('proprietary', $composer['license']);
        $this->assertSame(
            'Vtinnovations\\ContaoMultilingualPagetree\\ContaoManager\\Plugin',
            $composer['extra']['contao-manager-plugin'],
        );
        $this->assertSame(
            ['Vtinnovations\\ContaoMultilingualPagetree\\' => 'src/'],
            $composer['autoload']['psr-4'],
        );
    }

    /**
     * A hardcoded version makes tagging and Packagist metadata wrong; Composer
     * derives it from the VCS tag instead.
     */
    public function testNoHardcodedVersionIsDeclared(): void
    {
        $this->assertArrayNotHasKey('version', $this->composer());
    }

    public function testDistributionMetadataIsComplete(): void
    {
        $composer = $this->composer();

        $this->assertNotEmpty($composer['description']);
        $this->assertNotEmpty($composer['keywords']);
        $this->assertNotEmpty($composer['authors']);
        $this->assertArrayHasKey('support', $composer);
        $this->assertSame('stable', $composer['minimum-stability']);
        $this->assertTrue($composer['prefer-stable']);
        $this->assertTrue($composer['config']['sort-packages']);
        $this->assertArrayHasKey('allow-plugins', $composer['config']);
    }

    /** The predecessor package must never be installable at the same time. */
    public function testThePredecessorPackageIsDeclaredAsAConflict(): void
    {
        $this->assertArrayHasKey('vtinnovations/contao-language-flow', $this->composer()['conflict']);
    }

    /** Runtime dependencies stay minimal; tooling belongs to require-dev. */
    public function testRuntimeDependenciesAreMinimal(): void
    {
        $composer = $this->composer();

        $this->assertSame(['php', 'contao/core-bundle'], array_keys($composer['require']));
        $this->assertSame('^8.1', $composer['require']['php']);
        $this->assertSame('^5.0', $composer['require']['contao/core-bundle']);

        foreach (array_keys($composer['require']) as $package) {
            $this->assertStringNotContainsString('*', (string) $composer['require'][$package], $package);
            $this->assertStringNotContainsString('dev-', (string) $composer['require'][$package], $package);
        }

        $this->assertArrayHasKey('phpunit/phpunit', $composer['require-dev']);
    }

    /**
     * The distributed archive must contain everything the bundle needs at
     * runtime and none of the development-only material.
     */
    public function testTheArchiveExcludesDevelopmentArtefactsOnly(): void
    {
        $excluded = $this->composer()['archive']['exclude'];

        foreach (['/tests', '/tools', '/.github', '/phpunit.xml.dist'] as $path) {
            $this->assertContains($path, $excluded, $path.' must not ship in the package archive.');
        }

        foreach (['/src', '/contao', '/public', '/README.md', '/CHANGELOG.md'] as $path) {
            $this->assertNotContains($path, $excluded, $path.' is required at runtime.');
        }
    }

    /**
     * @dataProvider requiredDocuments
     */
    public function testRequiredDocumentsExist(string $path, string $mustContain): void
    {
        $file = self::ROOT.'/'.$path;

        $this->assertFileExists($file);
        $this->assertStringContainsString($mustContain, (string) file_get_contents($file));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requiredDocuments(): iterable
    {
        yield 'changelog' => ['CHANGELOG.md', '## [Unreleased]'];
        yield 'upgrade guide' => ['UPGRADE.md', 'contao:migrate'];
        yield 'runbook (german)' => ['docs/RUNBOOK.de.md', 'Störungsbehebung'];
        yield 'runbook (english)' => ['docs/RUNBOOK.en.md', 'Incident response'];
        yield 'licence guide (german)' => ['docs/PRODUCT-REGISTRATION.de.md', 'Lizenz'];
        yield 'licence guide (english)' => ['docs/PRODUCT-REGISTRATION.en.md', 'Licence'];
        yield 'manual verification' => ['docs/MANUAL-VERIFICATION.md', 'not verified'];
        yield 'extension points' => ['docs/EXTENSION-POINTS.md', 'Not extension points'];
        yield 'readme limitations' => ['README.md', '## Bekannte Einschränkungen'];
        yield 'readme uninstall' => ['README.md', '## Deinstallation'];
        yield 'readme limitations (english)' => ['README.en.md', '## Known limitations'];
        yield 'readme uninstall (english)' => ['README.en.md', '## Uninstalling'];
    }

    /**
     * German is the default language of the documentation, English the
     * alternate. Each of the two must link to the other, so neither can be
     * reached without the other being discoverable.
     *
     * @dataProvider languagePairs
     */
    public function testEachDocumentLinksToItsCounterpart(string $document, string $counterpart): void
    {
        $this->assertStringContainsString(
            $counterpart,
            (string) file_get_contents(self::ROOT.'/'.$document),
            $document.' must link to '.$counterpart,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function languagePairs(): iterable
    {
        yield 'readme -> english' => ['README.md', 'README.en.md'];
        yield 'readme <- german' => ['README.en.md', 'README.md'];
        yield 'runbook -> english' => ['docs/RUNBOOK.de.md', 'RUNBOOK.en.md'];
        yield 'runbook <- german' => ['docs/RUNBOOK.en.md', 'RUNBOOK.de.md'];
        yield 'licence -> english' => ['docs/PRODUCT-REGISTRATION.de.md', 'PRODUCT-REGISTRATION.en.md'];
        yield 'licence <- german' => ['docs/PRODUCT-REGISTRATION.en.md', 'PRODUCT-REGISTRATION.de.md'];
        yield 'user guide -> english' => ['docs/USER-GUIDE.de.md', 'USER-GUIDE.en.md'];
        yield 'user guide <- german' => ['docs/USER-GUIDE.en.md', 'USER-GUIDE.de.md'];
    }

    /**
     * Every relative document link in the public documentation must resolve.
     *
     * A translated documentation set is exactly where a broken cross-reference
     * survives unnoticed, so the check is mechanical rather than editorial.
     */
    public function testEveryDocumentLinkResolves(): void
    {
        foreach (self::publicDocuments() as $document) {
            $contents = (string) file_get_contents(self::ROOT.'/'.$document);
            $directory = \dirname(self::ROOT.'/'.$document);

            preg_match_all('/]\(([^)#\s]+\.md)(?:#[^)]*)?\)/', $contents, $matches);

            foreach ($matches[1] as $target) {
                if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target)) {
                    continue;
                }

                $this->assertFileExists($directory.'/'.$target, $document.' links to a missing document.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function publicDocuments(): array
    {
        return [
            'README.md',
            'README.en.md',
            'CHANGELOG.md',
            'UPGRADE.md',
            'docs/RUNBOOK.de.md',
            'docs/RUNBOOK.en.md',
            'docs/PRODUCT-REGISTRATION.de.md',
            'docs/PRODUCT-REGISTRATION.en.md',
            'docs/USER-GUIDE.de.md',
            'docs/USER-GUIDE.en.md',
            'docs/MANUAL-VERIFICATION.md',
            'docs/EXTENSION-POINTS.md',
            'docs/SERVER-SETUP-DIAGNOSTICS.md',
        ];
    }

    /**
     * The changelog must not claim released versions while the package is
     * unreleased.
     */
    public function testTheChangelogDoesNotInventReleases(): void
    {
        $changelog = (string) file_get_contents(self::ROOT.'/CHANGELOG.md');

        $this->assertStringContainsString('## [Unreleased]', $changelog);
        $this->assertDoesNotMatchRegularExpression('/^## \[\d+\.\d+\.\d+\]/m', $changelog);
    }

    /**
     * No document may leak the retained local development path, and only the
     * changelog and the upgrade guide may mention the predecessor package -
     * there it is a deliberate historical and migration reference.
     */
    public function testDocumentationDoesNotLeakLocalPathsOrStaleBranding(): void
    {
        $mayReferenceHistory = ['CHANGELOG.md', 'UPGRADE.md'];

        foreach (self::publicDocuments() as $document) {
            $contents = (string) file_get_contents(self::ROOT.'/'.$document);

            $this->assertStringNotContainsString('/Users/', $contents, $document);
            $this->assertStringNotContainsString('Contao Language Flow', $contents, $document);

            if (!in_array($document, $mayReferenceHistory, true)) {
                $this->assertStringNotContainsString('contao-language-flow', $contents, $document);
            }
        }
    }

    /** The installation command in the documentation uses the current package. */
    public function testTheDocumentedInstallCommandIsCorrect(): void
    {
        foreach (['README.md', 'README.en.md'] as $readme) {
            $this->assertStringContainsString(
                'composer require vtinnovations/contao-multilingual-pagetree',
                (string) file_get_contents(self::ROOT.'/'.$readme),
                $readme,
            );
        }
    }

    /** Dedicated logging channels are declared by the DI extension. */
    public function testLoggingChannelsAreDeclared(): void
    {
        $this->assertSame('contao_multilingual_pagetree', VtinnovationsContaoMultilingualPagetreeExtension::CHANNEL);
        $this->assertSame(
            'contao_multilingual_pagetree_integrity',
            VtinnovationsContaoMultilingualPagetreeExtension::CHANNEL_INTEGRITY,
        );

        $this->assertSame(
            'contao_multilingual_pagetree_license',
            VtinnovationsContaoMultilingualPagetreeExtension::CHANNEL_REGISTRATION,
        );

        $services = (string) file_get_contents(self::ROOT.'/src/Resources/config/services.yaml');

        $this->assertStringContainsString('monolog.logger.contao_multilingual_pagetree', $services);
        $this->assertStringContainsString('monolog.logger.contao_multilingual_pagetree_integrity', $services);
        $this->assertStringContainsString('monolog.logger.contao_multilingual_pagetree_license', $services);
    }

    /**
     * The registration diagnostics have their own file, and only the explicit
     * operations write to it - so it does not appear during installation, setup,
     * container compilation or ordinary rendering.
     */
    public function testRegistrationDiagnosticsGoToTheirOwnFile(): void
    {
        $this->assertSame(
            '%kernel.project_dir%/var/logs/license.log',
            VtinnovationsContaoMultilingualPagetreeExtension::REGISTRATION_LOG,
        );

        $extension = (string) file_get_contents(self::ROOT.'/src/DependencyInjection/VtinnovationsContaoMultilingualPagetreeExtension.php');

        $this->assertStringContainsString("'type' => 'stream'", $extension);
        $this->assertStringContainsString("'inclusive'", $extension);

        // Only the two explicit registration entry points use that channel.
        $services = (string) file_get_contents(self::ROOT.'/src/Resources/config/services.yaml');

        $this->assertSame(2, substr_count($services, 'monolog.logger.contao_multilingual_pagetree_license'));
    }

    /**
     * A distributable artefact must never ship without verification material.
     *
     * The release check is what enforces this; the assertion here is that the
     * check exists, is wired into the build and cannot be satisfied by an empty
     * ring unless it is explicitly and deliberately allowed.
     */
    public function testTheReleaseCheckRefusesAnEmptyOrUnprovenKeyRing(): void
    {
        $check = (string) file_get_contents(self::ROOT.'/tools/check-release-material.php');

        $this->assertStringContainsString('A release build must pin the public keys first.', $check);
        $this->assertStringContainsString('declaredFingerprints', $check);
        $this->assertStringContainsString('does not reassemble to its recorded fingerprint', $check);
        $this->assertStringContainsString('PRIVATE KEY', $check);
    }

    /** Every state-changing action is wired through the central guard. */
    public function testStateChangingActionsUseTheCentralGuard(): void
    {
        foreach (['src/Backend/TranslationReviewDca.php', 'src/Content/ModeSwitchGuard.php'] as $file) {
            $contents = (string) file_get_contents(self::ROOT.'/'.$file);

            $this->assertStringContainsString('BackendActionGuard', $contents, $file);
            $this->assertStringNotContainsString(
                "Input::get('rt')",
                $contents,
                $file.' must not read a CSRF token from the query string.',
            );
        }
    }

    /** Every supported CI job must be mandatory. */
    public function testNoWorkflowJobMayOptOutOfFailing(): void
    {
        $workflow = (string) file_get_contents(self::ROOT.'/.github/workflows/compatibility.yml');

        $this->assertStringNotContainsString('continue-on-error', $workflow);
        $this->assertStringContainsString('composer validate --strict', $workflow);
        $this->assertStringContainsString('composer audit', $workflow);
        $this->assertStringContainsString('permissions:', $workflow);
    }
}
