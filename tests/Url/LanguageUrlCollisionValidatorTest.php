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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Url;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryLanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\InvalidLanguageUrlException;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlCollisionValidator;

/**
 * Server-side mapping validation. An ambiguous configuration is always
 * rejected; a language is never silently chosen.
 */
class LanguageUrlCollisionValidatorTest extends TestCase
{
    private const ROOT = 1;
    private const OTHER_ROOT = 2;

    /** Same domain, distinct path prefixes: the standard valid setup. */
    public function testDistinctEntryPointsOnTheSameDomainAreAllowed(): void
    {
        $validator = $this->validator([
            $this->record(1, 'en', entryPoint: '/'),
            $this->record(2, 'de', entryPoint: '/de'),
        ]);

        $mapping = $validator->validate(self::ROOT, 3, 'ru', '', '', '/ru', true);

        $this->assertSame('www.xyz.com', $mapping->effectiveHostname);
        $this->assertSame('/ru', $mapping->effectiveEntryPoint);
    }

    /** Distinct domains may all use the domain root. */
    public function testTheDomainRootIsAllowedOnDistinctHostnames(): void
    {
        $validator = $this->validator([
            $this->record(1, 'en', entryPoint: '/'),
            $this->record(2, 'de', domain: 'www.xyz.de', entryPoint: '/'),
        ]);

        $mapping = $validator->validate(self::ROOT, 3, 'ru', '', 'www.xyz.ru', '/', true);

        $this->assertSame('www.xyz.ru', $mapping->effectiveHostname);
        $this->assertSame('/', $mapping->effectiveEntryPoint);
    }

    public function testDuplicateHostnameAndEntryPointIsRejected(): void
    {
        $validator = $this->validator([
            $this->record(1, 'en', entryPoint: '/'),
            $this->record(2, 'de', entryPoint: '/de'),
        ]);

        $this->expectValidationFailure(
            'duplicateMapping',
            fn () => $validator->validate(self::ROOT, 3, 'ru', '', '', '/de', true),
        );
    }

    /** Two languages may not both claim "/" on the same effective hostname. */
    public function testTwoLanguagesCannotShareTheDomainRootOfOneHostname(): void
    {
        $validator = $this->validator([$this->record(1, 'en', entryPoint: '/')]);

        $this->expectValidationFailure(
            'duplicateRootMapping',
            fn () => $validator->validate(self::ROOT, 2, 'de', '', 'www.xyz.com', '/', true),
        );
    }

    /** Mappings that only become identical after normalisation are rejected. */
    public function testMappingsThatCollideAfterNormalisationAreRejected(): void
    {
        $validator = $this->validator([
            $this->record(1, 'en', entryPoint: '/'),
            $this->record(2, 'de', domain: 'www.xyz.de', entryPoint: '/de'),
        ]);

        // "de" normalises to "/de" and "WWW.XYZ.DE." normalises to "www.xyz.de".
        $this->expectValidationFailure(
            'duplicateMapping',
            fn () => $validator->validate(self::ROOT, 3, 'ru', '', 'WWW.XYZ.DE.', 'de/', true),
        );
    }

    /** Two languages must not differ only by protocol. */
    public function testProtocolOnlyAmbiguityIsRejected(): void
    {
        $validator = $this->validator([$this->record(2, 'de', protocol: 'https', entryPoint: '/x')]);

        $this->expectValidationFailure(
            'protocolAmbiguity',
            fn () => $validator->validate(self::ROOT, 3, 'ru', 'http', '', '/x', true),
        );
    }

    /** An inherited mapping colliding with an explicit one is rejected too. */
    public function testAnInheritedMappingCannotCollideWithAnExplicitOne(): void
    {
        // German still uses the previous strategy, so it effectively owns /de.
        $validator = $this->validator([$this->record(2, 'de')]);

        $this->expectValidationFailure(
            'duplicateMapping',
            fn () => $validator->validate(self::ROOT, 3, 'ru', '', '', '/de', true),
        );
    }

    /** A hostname that belongs to another website root is rejected. */
    public function testAHostnameOfAnotherRootIsRejected(): void
    {
        $validator = $this->validator(
            [$this->record(1, 'en', entryPoint: '/')],
            [self::OTHER_ROOT => [$this->record(9, 'it', domain: 'shop.xyz.com', entryPoint: '/')]],
        );

        $this->expectValidationFailure(
            'crossRootConflict',
            fn () => $validator->validate(self::ROOT, 2, 'de', '', 'shop.xyz.com', '/', true),
        );

        // The other root's own primary hostname is protected in the same way.
        $this->expectValidationFailure(
            'crossRootConflict',
            fn () => $validator->validate(self::ROOT, 2, 'de', '', 'www.other.com', '/', true),
        );
    }

    /** A hostname the very same root already owns stays usable. */
    public function testTheOwnRootHostnameStaysUsable(): void
    {
        $validator = $this->validator([$this->record(1, 'en', entryPoint: '/')]);

        $mapping = $validator->validate(self::ROOT, 2, 'de', '', 'www.xyz.com', '/de', true);

        $this->assertSame('www.xyz.com', $mapping->effectiveHostname);
    }

    /** An invalid hostname or entry point never reaches the collision rules. */
    public function testInvalidValuesAreRejectedBeforeComparison(): void
    {
        $validator = $this->validator([]);

        $this->expectValidationFailure('domainScheme', fn () => $validator->validate(self::ROOT, 2, 'de', '', 'https://www.xyz.de', '/de', true));
        $this->expectValidationFailure('domainPath', fn () => $validator->validate(self::ROOT, 2, 'de', '', 'www.xyz.de/de', '/de', true));
        $this->expectValidationFailure('domainPort', fn () => $validator->validate(self::ROOT, 2, 'de', '', 'www.xyz.de:8080', '/de', true));
        $this->expectValidationFailure('entryPointTraversal', fn () => $validator->validate(self::ROOT, 2, 'de', '', '', '/de/../admin', true));
        $this->expectValidationFailure('entryPointUrl', fn () => $validator->validate(self::ROOT, 2, 'de', '', '', 'https://x/de', true));
    }

    /** An unpublished language occupies no URL and cannot collide. */
    public function testAnUnpublishedLanguageDoesNotCollide(): void
    {
        $validator = $this->validator([$this->record(2, 'de', entryPoint: '/x', published: false)]);

        $mapping = $validator->validate(self::ROOT, 3, 'ru', '', '', '/x', true);

        $this->assertSame('/x', $mapping->effectiveEntryPoint);
    }

    /** Saving a record against itself is never a collision. */
    public function testARecordNeverCollidesWithItself(): void
    {
        $validator = $this->validator([$this->record(2, 'de', entryPoint: '/de')]);

        $mapping = $validator->validate(self::ROOT, 2, 'de', '', '', '/de', true);

        $this->assertSame('/de', $mapping->effectiveEntryPoint);
    }

    public function testARecordWithoutARootIsRejected(): void
    {
        $this->expectValidationFailure(
            'unknownRoot',
            fn () => $this->validator([])->validate(0, 2, 'de', '', '', '/de', true),
        );
    }

    /**
     * A language with its own domain and no entry point occupies that domain's
     * root, so a second language cannot claim the same root.
     */
    public function testAnOwnDomainWithoutAnEntryPointOccupiesTheDomainRoot(): void
    {
        $validator = $this->validator([
            $this->record(1, 'en', entryPoint: '/'),
            // Own domain, no entry point: effectively www.xyz.ru/
            $this->record(2, 'ru', domain: 'www.xyz.ru'),
        ]);

        // An explicit "/" on the same host is the very same target.
        $this->expectValidationFailure(
            'duplicateRootMapping',
            fn () => $validator->validate(self::ROOT, 3, 'de', '', 'www.xyz.ru', '/', true),
        );

        // ...and so is another empty entry point on that host.
        $this->expectValidationFailure(
            'duplicateRootMapping',
            fn () => $validator->validate(self::ROOT, 3, 'de', '', 'www.xyz.ru', '', true),
        );
    }

    /** A path below that domain stays free. */
    public function testAPathBelowAnOwnDomainRootIsStillAvailable(): void
    {
        $validator = $this->validator([
            $this->record(1, 'en', entryPoint: '/'),
            $this->record(2, 'ru', domain: 'www.xyz.ru'),
        ]);

        $mapping = $validator->validate(self::ROOT, 3, 'de', '', 'www.xyz.ru', '/de', true);

        $this->assertSame('www.xyz.ru', $mapping->effectiveHostname);
        $this->assertSame('/de', $mapping->effectiveEntryPoint);
    }

    /** Without a domain, an empty entry point still means the language code. */
    public function testWithoutADomainAnEmptyEntryPointStillMeansTheLanguageCode(): void
    {
        $validator = $this->validator([$this->record(1, 'en', entryPoint: '/')]);

        $mapping = $validator->validate(self::ROOT, 2, 'de', '', '', '', true);

        $this->assertSame('/de', $mapping->effectiveEntryPoint, 'Existing records keep their behaviour.');
    }

    private function expectValidationFailure(string $reasonKey, callable $operation): void
    {
        try {
            $operation();
            $this->fail(sprintf('The mapping must be rejected with "%s".', $reasonKey));
        } catch (InvalidLanguageUrlException $exception) {
            $this->assertSame($reasonKey, $exception->reasonKey);
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>>            $records
     * @param array<int, list<array<string, mixed>>> $otherRoots
     */
    private function validator(array $records, array $otherRoots = []): LanguageUrlCollisionValidator
    {
        $roots = [
            self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en'],
        ];

        if ([] !== $otherRoots) {
            $roots[self::OTHER_ROOT] = ['host' => 'www.other.com', 'secure' => true, 'language' => 'fr'];
        }

        return new LanguageUrlCollisionValidator(
            new InMemoryLanguageUrlResolver($roots, [self::ROOT => $records] + $otherRoots),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function record(
        int $id,
        string $language,
        string $protocol = '',
        string $domain = '',
        string $entryPoint = '',
        bool $published = true,
    ): array {
        return [
            'id' => $id,
            'language' => $language,
            'urlProtocol' => $protocol,
            'urlDomain' => $domain,
            'urlEntryPoint' => $entryPoint,
            'published' => $published,
        ];
    }
}
