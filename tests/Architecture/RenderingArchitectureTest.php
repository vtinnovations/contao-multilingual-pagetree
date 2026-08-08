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
 * Guards the architectural contract of point 4: translations are applied before
 * Contao renders, and Contao stays responsible for the rendering itself.
 */
class RenderingArchitectureTest extends TestCase
{
    /**
     * The bundle must never recreate a content element itself.
     * (Requirements 4, 5, 32, 34 and 40)
     *
     * @dataProvider forbiddenRenderingPatterns
     */
    public function testNoManualContentElementRegenerationExists(string $pattern, string $reason): void
    {
        $matches = [];

        foreach ($this->sourceFiles(__DIR__.'/../../src') as $file) {
            $contents = file_get_contents($file);

            if (false !== $contents && str_contains($contents, $pattern)) {
                $matches[] = $file;
            }
        }

        $this->assertSame([], $matches, sprintf('"%s" must not be used: %s', $pattern, $reason));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenRenderingPatterns(): iterable
    {
        yield 'findClass' => ['findClass', 'renderer selection is Contao\'s responsibility'];
        yield 'ContentElement class' => ['Contao\ContentElement', 'legacy content element classes must not be referenced'];
        yield 'dynamic class instantiation' => ['new $class', 'content elements must never be constructed by this bundle'];
        yield 'generate call' => ['->generate()', 'no element may be rendered a second time'];
        yield 'article rebuild' => ['rebuildArticleElements', 'article content is rendered by Contao'];
        yield 'element buffer assembly' => ['->elements =', 'rendered article elements must not be replaced'];
        yield 'content type table' => ['TL_CTE', 'no custom content type switch is allowed'];
    }

    /**
     * The rendering layer must not query content records on its own.
     */
    public function testRenderingLayerDoesNotQueryContentItself(): void
    {
        $matches = [];

        foreach ([__DIR__.'/../../src/EventListener', __DIR__.'/../../src/Translation'] as $directory) {
            foreach ($this->sourceFiles($directory) as $file) {
                $contents = (string) file_get_contents($file);

                if (str_contains($contents, 'Database::getInstance')) {
                    $matches[] = $file;
                }
            }
        }

        $this->assertSame([], $matches, 'The rendering layer must resolve translations through the translation record locator.');
    }

    /**
     * Page availability is decided in one place only: no listener, route
     * provider, controller or URL resolver may look a page translation up on
     * its own. (Point 5)
     */
    public function testPageAvailabilityIsDecidedOnlyByTheCentralService(): void
    {
        $matches = [];

        foreach ($this->sourceFiles(__DIR__.'/../../src') as $file) {
            if (str_contains($file, 'src/Model/')) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'PageTranslationModel')) {
                $matches[] = $file;
            }
        }

        $this->assertSame([], $matches, 'Page translations must be resolved through PageAvailabilityResolver.');
    }

    /**
     * Availability must never depend on the visitor's browser or session.
     */
    public function testAvailabilityNeverDependsOnBrowserOrSessionLanguage(): void
    {
        $matches = [];

        foreach ($this->sourceFiles(__DIR__.'/../../src/Availability') as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['getSession', 'getPreferredLanguage', 'ACCEPT_LANGUAGE', 'cookies'] as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $matches[] = $file.': '.$pattern;
                }
            }
        }

        $this->assertSame([], $matches);
    }

    /**
     * Switcher and metadata output must never be built from the unfiltered
     * language configuration, and the template must not decide availability.
     * (Point 6)
     */
    public function testSwitcherAndMetadataAreBuiltFromTheAvailabilityServices(): void
    {
        $template = (string) file_get_contents(__DIR__.'/../../contao/templates/mod_language_switcher.html.twig');

        // The template renders resolved states only.
        foreach (['findTranslation', 'PageAvailabilityResolver', 'pageAvailabilityMode', 'hide_untranslated', 'show_default'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $template, 'The template must not decide availability.');
        }

        $this->assertStringContainsString('lang.available', $template, 'The template must render the availability state already resolved by the builder.');

        $this->assertStringContainsString('aria-disabled="true"', $template);
        $this->assertStringContainsString('aria-current="page"', $template);

        $controller = (string) file_get_contents(__DIR__.'/../../src/Controller/FrontendModule/LanguageSwitcherController.php');
        $this->assertStringContainsString('LanguageSwitcherBuilder', $controller);
        $this->assertStringNotContainsString('getCanonicalPagePath', $controller, 'The controller must not resolve URLs itself.');
    }

    /**
     * Exactly one place emits canonical and hreflang metadata.
     */
    public function testOnlyOneListenerEmitsLanguageMetadata(): void
    {
        $emitters = [];

        foreach ($this->sourceFiles(__DIR__.'/../../src') as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'rel="alternate"') || str_contains($contents, 'setCanonicalUri')) {
                $emitters[] = basename($file);
            }
        }

        $this->assertSame(['LanguageMetadataListener.php'], $emitters);
    }

    /**
     * Review status is editorial backend metadata: it must never be read by the
     * frontend rendering, routing, availability or metadata layers. (Point 8)
     */
    public function testReviewStatusNeverReachesTheFrontend(): void
    {
        $frontendDirectories = [
            __DIR__.'/../../src/Availability',
            __DIR__.'/../../src/Detail',
            __DIR__.'/../../src/EventListener',
            __DIR__.'/../../src/Metadata',
            __DIR__.'/../../src/Routing',
            __DIR__.'/../../src/Switcher',
            __DIR__.'/../../src/Controller',
        ];

        $matches = [];

        foreach ($frontendDirectories as $directory) {
            foreach ($this->sourceFiles($directory) as $file) {
                $contents = (string) file_get_contents($file);

                foreach (['reviewStatus', 'reviewedSourceRevision', 'ReviewStatus', 'needs_review'] as $pattern) {
                    if (str_contains($contents, $pattern)) {
                        $matches[] = basename($file).': '.$pattern;
                    }
                }
            }
        }

        $this->assertSame([], $matches, 'Review metadata must stay out of the frontend.');
    }

    /**
     * Source-change detection must not fall back to comparing timestamps.
     */
    public function testSourceChangeDetectionDoesNotCompareTimestamps(): void
    {
        $matches = [];

        foreach ($this->sourceFiles(__DIR__.'/../../src/Review') as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, "'tstamp'") || str_contains($contents, 'md5(')) {
                $matches[] = basename($file);
            }
        }

        $this->assertSame([], $matches, 'Fingerprints use the field policy and SHA-256, never tstamp or MD5.');
    }

    /**
     * The overlay is attached to Contao's pre-render hooks.
     */
    public function testPreRenderHooksAreRegistered(): void
    {
        $content = (string) file_get_contents(__DIR__.'/../../src/EventListener/ContentTranslationListener.php');
        $article = (string) file_get_contents(__DIR__.'/../../src/EventListener/ArticleTranslationListener.php');

        $this->assertStringContainsString("#[AsHook('isVisibleElement')]", $content);
        $this->assertStringContainsString("#[AsHook('isVisibleElement')]", $article);
        $this->assertStringContainsString("#[AsHook('getArticle')]", $article);
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
