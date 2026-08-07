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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Support;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageDocument;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageSeal;
use Vtinnovations\ContaoMultilingualPagetree\Support\CanonicalInput;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

/**
 * Fixed test vectors for the two signing profiles.
 *
 * These byte strings are the contract with the issuing side: if any of them
 * changes, signatures made elsewhere stop verifying here, so the change must be
 * deliberate and coordinated.
 */
final class CanonicalInputTest extends TestCase
{
    public function testDocumentVectorIsStable(): void
    {
        $fields = PackageFactory::documentFields();
        $fields['signature'] = 'ignored-by-the-canonical-input';

        $document = PackageDocument::fromArray($fields, new CanonicalHost());

        $expected = '{"free_available":true,"license_domain":"example.com",'
            .'"license_domains":["example.com","staging.example.com"],"license_expires_at":1787472547,'
            .'"license_features":[],"license_issued_at":1784794147,"license_key":"CMP-TEST-0000-0000",'
            .'"license_lifetime":false,"license_max_domains":3,"license_package":"pro","license_starts_at":1784794147,'
            .'"license_verified_at":1784880547,"license_version":7,"project":"Contao Multilingual Pagetree",'
            .'"project_slug":"contao-multilingual-pagetree","schema_version":2,"validation_status":"valid"}';

        self::assertSame($expected, $document->signingInput());
    }

    /** The document signature never covers the document's own signature field. */
    public function testDocumentVectorIgnoresTheSignatureField(): void
    {
        $hosts = new CanonicalHost();
        $first = PackageFactory::documentFields();
        $first['signature'] = 'aaa';
        $second = PackageFactory::documentFields();
        $second['signature'] = 'bbb';

        self::assertSame(
            PackageDocument::fromArray($first, $hosts)->signingInput(),
            PackageDocument::fromArray($second, $hosts)->signingInput(),
        );
    }

    /** Field order on the wire never changes the canonical bytes. */
    public function testDocumentVectorIsIndependentOfWireFieldOrder(): void
    {
        $hosts = new CanonicalHost();
        $fields = PackageFactory::documentFields();
        $reversed = array_reverse($fields, true);

        self::assertSame(
            PackageDocument::fromArray($fields, $hosts)->signingInput(),
            PackageDocument::fromArray($reversed, $hosts)->signingInput(),
        );
    }

    public function testLifetimeAndFeatureListRendering(): void
    {
        $document = PackageDocument::fromArray(array_merge(PackageFactory::documentFields(), [
            'license_features' => ['free_content_mode', 'integrity_repair'],
            'license_lifetime' => true,
            'license_expires_at' => null,
            'signature' => 'x',
        ]), new CanonicalHost());

        $input = $document->signingInput();

        // The list keeps its exact order and stays a JSON array.
        self::assertStringContainsString('"license_features":["free_content_mode","integrity_repair"]', $input);
        self::assertStringContainsString('"license_expires_at":null', $input);
        self::assertStringContainsString('"license_lifetime":true', $input);
    }

    /** Scalar types survive: false is not "false" and null is not 0. */
    public function testScalarTypesArePreserved(): void
    {
        self::assertSame(
            '{"a":true,"b":false,"c":null,"d":-5,"e":"","f":"0"}',
            CanonicalInput::document(['f' => '0', 'e' => '', 'd' => -5, 'c' => null, 'b' => false, 'a' => true]),
        );
    }

    /** Slashes and Unicode stay unescaped. */
    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        self::assertSame(
            '{"path":"/api/v1/verify","text":"Sprachbäume"}',
            CanonicalInput::document(['text' => 'Sprachbäume', 'path' => '/api/v1/verify']),
        );
    }

    public function testNestedMapsAreSortedAtEveryDepth(): void
    {
        self::assertSame(
            '{"outer":{"a":1,"b":{"x":1,"y":2}},"z":[{"a":1,"b":2}]}',
            CanonicalInput::document(['z' => [['b' => 2, 'a' => 1]], 'outer' => ['b' => ['y' => 2, 'x' => 1], 'a' => 1]]),
        );
    }

    /** A float cannot be rendered identically on both sides, so it is refused. */
    public function testFloatsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalInput::document(['value' => 1.5]);
    }

    public function testSealVectorIsStable(): void
    {
        $seal = PackageSeal::fromArray([
            'project' => 'Contao Multilingual Pagetree',
            'project_slug' => 'contao-multilingual-pagetree',
            'license_version' => 7,
            'license_md5' => '0123456789abcdef0123456789abcdef',
            'generated_at' => 1784880547,
            'key_id' => 'test-key-a',
            'signature_algorithm' => 'ed25519',
            'signature' => 'ignored',
        ]);

        $expected = '{"generated_at":1784880547,"key_id":"test-key-a",'
            .'"license_md5":"0123456789abcdef0123456789abcdef","license_version":7,'
            .'"project":"Contao Multilingual Pagetree","project_slug":"contao-multilingual-pagetree",'
            .'"signature_algorithm":"ed25519"}';

        self::assertSame($expected, $seal->signingInput());
    }

    public function testRequestVectorIsStable(): void
    {
        $expected = "POST\n"
            .ProductProfile::ENDPOINT_PATH."\n"
            ."req-00000001\n"
            ."1784880547\n"
            ."nonce-000000000001\n"
            .'015abd7f5cc57a2dd94b7590f04ad8084273905ee33ec5cebeae62276a97f862';

        self::assertSame($expected, CanonicalInput::request(
            'post',
            ProductProfile::ENDPOINT_PATH,
            'req-00000001',
            1784880547,
            'nonce-000000000001',
            hash('sha256', '{"a":1}'),
        ));
    }

    /** Exactly six lines, joined with one newline and with none at the end. */
    public function testRequestVectorHasNoTrailingNewline(): void
    {
        $input = CanonicalInput::request('POST', '/x', 'r', 1, 'n', hash('sha256', ''));

        self::assertCount(6, explode("\n", $input));
        self::assertStringEndsNotWith("\n", $input);
    }

    /** The key id selects the key and is deliberately not signed. */
    public function testRequestVectorDoesNotContainTheKeyId(): void
    {
        self::assertStringNotContainsString(
            'vtone-2026a',
            CanonicalInput::request('POST', '/x', 'r', 1, 'n', hash('sha256', '')),
        );
    }

    /**
     * @dataProvider unusableRequestComponents
     */
    public function testUnusableRequestComponentsAreRefused(string $requestId, string $nonce, string $digest): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalInput::request('POST', '/x', $requestId, 1, $nonce, $digest);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unusableRequestComponents(): iterable
    {
        $valid = hash('sha256', '');

        yield 'newline in request id' => ["a\nb", 'n', $valid];
        yield 'carriage return in nonce' => ['r', "a\rb", $valid];
        yield 'empty nonce' => ['r', '', $valid];
        yield 'uppercase digest' => ['r', 'n', strtoupper($valid)];
        yield 'short digest' => ['r', 'n', 'abcd'];
    }
}
