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
use Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationReviewDca;

/**
 * Content elements have no editorial review of their own; pages keep theirs.
 *
 * A content element is reviewed as part of the page it sits on. It therefore
 * carries no review state, no status panel and no "mark as reviewed" action -
 * and its language tabs must not report a status it has no way to maintain or
 * act on. Page, article, news, event and FAQ translations are unaffected.
 */
final class ContentReviewAbsenceTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private function read(string $path): string
    {
        $file = self::ROOT.'/'.$path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    // ---------------------------------------------------------------- content

    public function testTheReviewWorkflowDoesNotGovernContentTranslations(): void
    {
        self::assertFalse(TranslationReviewDca::governs('tl_content_translation'));
        self::assertNotContains('tl_content_translation', TranslationReviewDca::REVIEWED_TABLES);
    }

    /** No review layer is registered on the content translation definition. */
    public function testTheContentDefinitionRegistersNoReviewLayer(): void
    {
        $dca = $this->read('contao/dca/tl_content_translation.php');

        self::assertStringNotContainsString('TranslationReviewDca', $dca);
        self::assertStringNotContainsString('reviewStatus', $dca);
        self::assertStringNotContainsString('reviewInfo', $dca);
    }

    /** The source content definition registers no review refresh either. */
    public function testTheContentSourceRegistersNoReviewLayer(): void
    {
        self::assertStringNotContainsString(
            'TranslationReviewDca',
            $this->read('contao/dca/tl_content.php'),
        );
    }

    /**
     * The badge is the one place review state reached a content tab, so the
     * decoration has to ask whether the table is governed at all.
     */
    public function testTabDecorationIsGatedOnTheGovernedTableSet(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        self::assertStringContainsString('TranslationReviewDca::governs($translationTable)', $tabs);

        // The gate must sit before any review collaborator is consulted.
        $gate = strpos($tabs, 'TranslationReviewDca::governs($translationTable)');
        $resolve = strpos($tabs, '$this->reviewResolver->resolve(');

        self::assertIsInt($gate);
        self::assertIsInt($resolve);
        self::assertLessThan($resolve, $gate, 'The badge must be refused before the resolver runs.');
    }

    /** An ungoverned table contributes no markup at all - not even an empty one. */
    public function testAnUngovernedTableContributesNoBadgeMarkup(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        // The badge is concatenated straight onto the label, so an empty string
        // leaves no element, no separator and no spacing behind.
        self::assertStringContainsString(".StringUtil::specialchars(\$tab['label']).\$tab['badge'].", $tabs);
        self::assertStringContainsString("'badge' => \$reviewBadges[\$code] ?? ''", $tabs);
    }

    // ---------------------------------------------------------------- pages

    /**
     * @dataProvider reviewedTables
     */
    public function testTheReviewWorkflowStillGovernsEveryOtherTranslation(string $table): void
    {
        self::assertTrue(TranslationReviewDca::governs($table));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reviewedTables(): iterable
    {
        yield 'pages' => ['tl_page_translation'];
        yield 'articles' => ['tl_article_translation'];
        yield 'news' => ['tl_news_translation'];
        yield 'events' => ['tl_calendar_events_translation'];
        yield 'faqs' => ['tl_faq_translation'];
    }

    /**
     * The declared set and the definitions that actually register the layer must
     * not drift apart: a table added to one and not the other would either lose
     * its badge or regain one it should not have.
     */
    public function testTheDeclaredSetMatchesTheDefinitionsThatRegisterTheLayer(): void
    {
        $registered = [];

        foreach (glob(self::ROOT.'/contao/dca/*_translation.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'TranslationReviewDca::configure(')) {
                $registered[] = basename($file, '.php');
            }
        }

        sort($registered);
        $declared = TranslationReviewDca::REVIEWED_TABLES;
        sort($declared);

        self::assertSame($declared, $registered);
    }

    /** Page review keeps its status, its action and its persistence. */
    public function testPageReviewRemainsFullyWired(): void
    {
        $dca = $this->read('contao/dca/tl_page_translation.php');
        self::assertStringContainsString("TranslationReviewDca::configure('tl_page_translation')", $dca);

        self::assertStringContainsString(
            "TranslationReviewDca::configureSource('tl_page')",
            $this->read('contao/dca/tl_page.php'),
        );

        $review = $this->read('src/Backend/TranslationReviewDca.php');
        self::assertStringContainsString('contaoMultilingualPagetreeMarkReviewed', $review);
        self::assertStringContainsString('reviewOperation', $review);
        self::assertStringContainsString('markReviewed', $review);
    }

    /** Fingerprints and stale detection stay in place for the governed tables. */
    public function testFingerprintAndStaleDetectionRemainWired(): void
    {
        self::assertFileExists(self::ROOT.'/src/Review/SourceFingerprintCalculator.php');
        self::assertFileExists(self::ROOT.'/src/Review/TranslationReviewResolver.php');
        self::assertFileExists(self::ROOT.'/src/Review/TranslationReviewMarker.php');

        // Stale detection is the fingerprint comparison against the reviewed
        // revision; the status enum is only its result.
        $resolver = $this->read('src/Review/TranslationReviewResolver.php');
        self::assertStringContainsString('reviewedSourceRevision', $resolver);
        self::assertStringContainsString('createFingerprint(', $resolver);
        self::assertStringContainsString('equalsHash(', $resolver);
        self::assertStringContainsString('ReviewStatus::NeedsReview', $resolver);

        // The DCA persists both review columns through the resolver's constants.
        $review = $this->read('src/Backend/TranslationReviewDca.php');
        self::assertStringContainsString('TranslationReviewResolver::FIELD_REVISION', $review);
        self::assertStringContainsString('TranslationReviewResolver::FIELD_STATUS', $review);
    }

    /** The shared review services are still present and still shared. */
    public function testSharedReviewServicesWereNotRemoved(): void
    {
        $services = $this->read('src/Resources/config/services.yaml');

        foreach ([
            'Review\TranslationReviewResolver',
            'Review\TranslationReviewMarker',
            'Review\ReviewBadgeRenderer',
            'Backend\TranslationReviewDca',
        ] as $service) {
            self::assertStringContainsString($service, $services, $service);
        }
    }

    // ------------------------------------------------- the persistence fix stands

    /**
     * The content translation store still writes only columns it has, and still
     * seeds no review state. This is the fix that made saving work at all.
     */
    public function testTheContentPersistenceFixIsIntact(): void
    {
        $repository = $this->read('src/Content/ContentTranslationRepository.php');

        self::assertStringContainsString("hasColumn('reviewStatus')", $repository);
        self::assertStringContainsString('private function writable(array $values): array', $repository);
        self::assertStringNotContainsString(
            "\$values['reviewStatus'] ??= ReviewStatus::Unreviewed->value;\n\n                \$this->connection->insert(self::TABLE, \$values);",
            $repository,
            'The unconditional review seed must not come back.',
        );
    }
}
