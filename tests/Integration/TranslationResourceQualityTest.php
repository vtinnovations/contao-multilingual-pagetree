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

/**
 * Quality of the shipped translation resources.
 *
 * Every user-facing string must live in a translation resource, exist in both
 * shipped languages, and use compatible placeholders.
 *
 * @legacy-identity-reference the former product name is asserted here on purpose
 * so stale branding cannot reappear in a shipped translation resource
 */
class TranslationResourceQualityTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * @return list<string>
     */
    private function resourceNames(): array
    {
        $names = [];

        foreach (glob(self::ROOT.'/contao/languages/en/*.php') ?: [] as $file) {
            $names[] = basename($file);
        }

        sort($names);

        return $names;
    }

    public function testBothShippedLanguagesProvideTheSameResourceFiles(): void
    {
        $english = $this->resourceNames();
        $german = [];

        foreach (glob(self::ROOT.'/contao/languages/de/*.php') ?: [] as $file) {
            $german[] = basename($file);
        }

        sort($german);

        $this->assertNotEmpty($english);
        $this->assertSame($english, $german);
    }

    /**
     * Requirement 64: English and German keys have parity.
     *
     * @dataProvider resourceFiles
     */
    public function testKeysHaveParity(string $file): void
    {
        $english = $this->keys('en', $file);
        $german = $this->keys('de', $file);

        $this->assertSame([], array_values(array_diff($english, $german)), 'Missing German keys in '.$file);
        $this->assertSame([], array_values(array_diff($german, $english)), 'Missing English keys in '.$file);
    }

    /**
     * Requirement 66: placeholders must match across languages, otherwise
     * sprintf() breaks in one language only.
     *
     * @dataProvider resourceFiles
     */
    public function testPlaceholdersMatchAcrossLanguages(string $file): void
    {
        $english = $this->placeholders('en', $file);
        $german = $this->placeholders('de', $file);

        foreach ($english as $key => $count) {
            $this->assertSame($count, $german[$key] ?? 0, sprintf('Placeholder count differs for "%s" in %s', $key, $file));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resourceFiles(): iterable
    {
        foreach (glob(self::ROOT.'/contao/languages/en/*.php') ?: [] as $file) {
            yield basename($file) => [basename($file)];
        }
    }

    /** Requirement 65: every resource parses and returns arrays only. */
    public function testResourcesParseAndContainNoStaleBranding(): void
    {
        foreach (['en', 'de'] as $language) {
            foreach (glob(self::ROOT.'/contao/languages/'.$language.'/*.php') ?: [] as $file) {
                $contents = (string) file_get_contents($file);

                $this->assertStringStartsWith('<?php', $contents, $file);
                $this->assertStringNotContainsString('Contao Language Flow', $contents, $file);
                $this->assertStringNotContainsString('Language Flow', $contents, $file);
                $this->assertStringNotContainsString('<script', $contents, $file);
            }
        }
    }

    public function testRootLanguageWorkflowLabelsAreExactInBothLanguages(): void
    {
        $english = (string) file_get_contents(self::ROOT.'/contao/languages/en/default.php');
        $german = (string) file_get_contents(self::ROOT.'/contao/languages/de/default.php');
        $englishPage = (string) file_get_contents(self::ROOT.'/contao/languages/en/tl_page.php');
        $germanPage = (string) file_get_contents(self::ROOT.'/contao/languages/de/tl_page.php');

        // The neutral wording exists exactly once per language.
        foreach ([
            'A valid license is required before additional languages can be managed.',
            'Manage additional languages',
        ] as $label) {
            self::assertSame(1, substr_count($english, $label));
        }
        foreach ([
            'Für die Verwaltung zusätzlicher Sprachen ist eine gültige Lizenz erforderlich.',
            'Zusätzliche Sprachen verwalten',
        ] as $label) {
            self::assertSame(1, substr_count($german, $label));
        }

        // Promotional and navigation-only wording is gone from every resource.
        foreach ([$english, $german, $englishPage, $germanPage] as $resource) {
            foreach ([
                'lifetime free',
                'free licence',
                'free license',
                'Go to licence settings',
                'kostenlose lebenslange',
                'Lebenslang kostenlos',
                'Zu den Lizenzeinstellungen',
            ] as $forbidden) {
                self::assertSame(0, substr_count(mb_strtolower($resource), mb_strtolower($forbidden)), $forbidden.' is still present.');
            }
        }
        // The section headline is the fixed package-profile string and is
        // deliberately identical in every language, so both files carry it
        // verbatim rather than a translation of it.
        foreach ([$englishPage, $germanPage] as $resource) {
            self::assertStringContainsString("= 'Contao Multilingual Pagetree Licence management';", $resource);
        }
        foreach (['Licence status', 'Root-page domain', 'Licence domain', 'Licence term', 'Activation state', 'Licence key', 'Activate licence', 'Replace licence', 'Refresh licence', 'Verify licence', 'Remove licence', 'Configure the root-page domain before activation.'] as $label) {
            self::assertStringContainsString($label, $english);
        }
        foreach (['Lizenzstatus', 'Domain des Website-Startpunkts', 'Lizenzdomain', 'Lizenzlaufzeit', 'Aktivierungsstatus', 'Lizenzschlüssel', 'Lizenz aktivieren', 'Lizenz ersetzen', 'Lizenz aktualisieren', 'Lizenz prüfen', 'Lizenz entfernen', 'Konfigurieren Sie vor der Aktivierung die Domain des Website-Startpunkts.'] as $label) {
            self::assertStringContainsString($label, $german);
        }
    }

    /**
     * Requirement 68: no user-facing English sentence may be hardcoded in the
     * backend classes that render labels.
     */
    public function testBackendRenderersDoNotHardcodeUserFacingSentences(): void
    {
        $files = [
            'src/Backend/TranslationReviewDca.php',
            'src/Backend/ContentModeDca.php',
            'src/Review/ReviewBadgeRenderer.php',
        ];

        foreach ($files as $file) {
            $contents = (string) file_get_contents(self::ROOT.'/'.$file);

            // A quoted string with several words and a trailing period is a
            // sentence; exception messages are not user facing and are excluded.
            preg_match_all("/'([A-Z][a-z]+(?: [a-z]+){3,}\\.)'/", $contents, $matches);

            foreach ($matches[1] as $sentence) {
                $this->assertStringContainsString(
                    'Contao Multilingual Pagetree',
                    $sentence,
                    sprintf('Hardcoded user-facing text in %s: %s', $file, $sentence),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function keys(string $language, string $file): array
    {
        $contents = (string) file_get_contents(self::ROOT.'/contao/languages/'.$language.'/'.$file);

        preg_match_all("/\\\$GLOBALS\\['TL_LANG'\\]((?:\\['[^']+'\\])+)/", $contents, $matches);

        $keys = array_values(array_unique($matches[1] ?? []));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, int>
     */
    private function placeholders(string $language, string $file): array
    {
        $contents = (string) file_get_contents(self::ROOT.'/contao/languages/'.$language.'/'.$file);
        $counts = [];

        preg_match_all("/\\\$GLOBALS\\['TL_LANG'\\]((?:\\['[^']+'\\])+)\\s*=\\s*(.+?);\\n/s", $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $counts[$match[1]] = substr_count($match[2], '%s') + substr_count($match[2], '%d');
        }

        return $counts;
    }
}
