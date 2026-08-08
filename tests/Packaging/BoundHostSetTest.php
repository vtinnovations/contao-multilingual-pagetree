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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Packaging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageDocument;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageFormatException;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

/**
 * The signed exact-host set.
 *
 * One licence may be bound to several hosts, and the issuer signs the complete
 * set into every current document. The rules the installation has to hold up
 * are narrow on purpose:
 *
 *  - the set is authorisation, and only exact members are authorised;
 *  - the set arrives canonical and is never repaired locally, because the bytes
 *    that were signed include its order;
 *  - the reported allowance is information, not a second gate;
 *  - a document from before the set existed authorises nothing until a refresh
 *    supplies it, and is still preserved.
 */
final class BoundHostSetTest extends TestCase
{
    private CanonicalHost $hosts;

    protected function setUp(): void
    {
        $this->hosts = new CanonicalHost();
    }

    public function testACanonicalSetIsAcceptedAndAuthorisesExactlyItsMembers(): void
    {
        $document = $this->document();

        self::assertFalse($document->isLegacyHostBinding());
        self::assertSame(['example.com', 'staging.example.com'], $document->boundHosts);
        self::assertSame(3, $document->boundHostAllowance);

        self::assertTrue($document->authorises('example.com', $this->hosts));
        self::assertTrue($document->authorises('staging.example.com', $this->hosts));
    }

    /** Representation is canonicalised; scope never is. */
    public function testAMemberIsRecognisedRegardlessOfItsRepresentation(): void
    {
        $document = $this->document();

        foreach (['EXAMPLE.com', 'example.com.', 'example.com:443'] as $representation) {
            self::assertTrue($document->authorises($representation, $this->hosts), $representation);
        }
    }

    /**
     * Every neighbouring identity is a different identity. This is the whole
     * point of an exact-host set, so it is asserted host by host.
     */
    #[DataProvider('unauthorisedHosts')]
    public function testAHostOutsideTheSetIsNeverAuthorised(string $host): void
    {
        self::assertFalse($this->document()->authorises($host, $this->hosts));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unauthorisedHosts(): iterable
    {
        yield 'www counterpart' => ['www.example.com'];
        yield 'sibling subdomain' => ['shop.example.com'];
        yield 'nested subdomain' => ['admin.staging.example.com'];
        yield 'parent domain' => ['com'];
        yield 'suffix lookalike' => ['malicious-example.com'];
        yield 'longer host containing a member' => ['example.com.attacker.test'];
        yield 'unrelated host' => ['attacker.test'];
        yield 'wildcard' => ['*.example.com'];
        yield 'empty' => [''];
    }

    /** The operation host is one member of the set, not a binding beside it. */
    public function testTheOperationHostMustBelongToTheSet(): void
    {
        $this->expectException(PackageFormatException::class);
        $this->document(['license_domain' => 'other.example.com', 'license_domains' => ['example.com']]);
    }

    /**
     * The set takes part in the canonical signing input, where list order is
     * preserved. Sorting it locally would verify bytes the issuer never signed.
     */
    #[DataProvider('unusableSets')]
    public function testAnUnusableSetIsRejectedRatherThanRepaired(mixed $domains): void
    {
        $this->expectException(PackageFormatException::class);
        $this->document(['license_domains' => $domains]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableSets(): iterable
    {
        yield 'unsorted' => [['staging.example.com', 'example.com']];
        yield 'duplicate' => [['example.com', 'example.com']];
        yield 'empty' => [[]];
        yield 'wildcard entry' => [['*.example.com', 'example.com']];
        yield 'uppercase entry' => [['Example.com']];
        yield 'trailing dot entry' => [['example.com.']];
        yield 'entry with port' => [['example.com:8080']];
        yield 'ip entry' => [['192.0.2.10']];
        yield 'url entry' => [['https://example.com/']];
        yield 'non-string entry' => [['example.com', 42]];
        yield 'json object' => [['first' => 'example.com']];
        yield 'not an array' => ['example.com'];
    }

    /**
     * `9999` is the instance-bound report. It says how many hosts the product
     * may bind, never that any host will do.
     */
    public function testTheInstanceBoundAllowanceIsNotAWildcard(): void
    {
        $document = $this->document(['license_max_domains' => 9999]);

        self::assertSame(9999, $document->boundHostAllowance);
        self::assertTrue($document->authorises('example.com', $this->hosts));
        self::assertFalse($document->authorises('anything.test', $this->hosts));
    }

    /**
     * The issuer keeps existing bindings alive after lowering an allowance. A
     * local count guard would take those installations dark.
     */
    public function testMoreBoundHostsThanTheAllowanceStaysValid(): void
    {
        $document = $this->document(['license_max_domains' => 1]);

        self::assertSame(1, $document->boundHostAllowance);
        self::assertCount(2, (array) $document->boundHosts);
        self::assertTrue($document->authorises('staging.example.com', $this->hosts));
    }

    #[DataProvider('unusableAllowances')]
    public function testAnUnusableAllowanceIsRejected(mixed $allowance): void
    {
        $this->expectException(PackageFormatException::class);
        $this->document(['license_max_domains' => $allowance]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableAllowances(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'numeric string' => ['3'];
        yield 'float' => [3.0];
        yield 'null' => [null];
    }

    /**
     * A document from before the set existed is authentic and readable, so it
     * can still be compared and rolled back to - but it says nothing about
     * which hosts are authorised, and nothing local may fill that in.
     */
    public function testALegacyDocumentParsesButAuthorisesNothing(): void
    {
        $document = $this->document(null);

        self::assertTrue($document->isLegacyHostBinding());
        self::assertNull($document->boundHosts);
        self::assertNull($document->boundHostAllowance);
        self::assertFalse($document->authorises('example.com', $this->hosts));
        self::assertSame('example.com', $document->boundHost);
    }

    /** Half of the pair is neither a legacy document nor a current one. */
    #[DataProvider('halfPairs')]
    public function testHalfOfThePairIsRejected(string $keep): void
    {
        $fields = PackageFactory::documentFields();

        foreach (PackageDocument::BOUND_HOST_FIELDS as $field) {
            if ($field !== $keep) {
                unset($fields[$field]);
            }
        }

        $this->expectException(PackageFormatException::class);
        PackageDocument::fromArray($fields, $this->hosts);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function halfPairs(): iterable
    {
        yield 'set without allowance' => ['license_domains'];
        yield 'allowance without set' => ['license_max_domains'];
    }

    /** The set is part of what is signed, so it is part of the signing input. */
    public function testTheSetAndTheAllowanceAreCoveredByTheSignature(): void
    {
        $input = $this->document()->signingInput();

        self::assertStringContainsString('"license_domains":["example.com","staging.example.com"]', $input);
        self::assertStringContainsString('"license_max_domains":3', $input);
    }

    /**
     * @param array<string, mixed>|null $overrides null builds a pre-upgrade document
     */
    private function document(?array $overrides = []): PackageDocument
    {
        $fields = PackageFactory::documentFields();

        if (null === $overrides) {
            foreach (PackageDocument::BOUND_HOST_FIELDS as $field) {
                unset($fields[$field]);
            }

            $overrides = [];
        }

        return PackageDocument::fromArray(array_merge($fields, $overrides), $this->hosts);
    }
}
