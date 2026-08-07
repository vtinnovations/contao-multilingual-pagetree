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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Security\BackendActionGuard;

/**
 * The guard is the single server-side gate for every state-changing backend
 * action of the bundle.
 */
class BackendActionGuardTest extends TestCase
{
    /**
     * Requirement 5: a state-changing action can never be triggered by a link,
     * a prefetch or an image request.
     *
     * @dataProvider readMethods
     */
    public function testReadMethodsAreRefused(string $method): void
    {
        $guard = new BackendActionGuard();

        $this->assertFalse($guard->isWriteMethod(Request::create('/contao', $method)));
        $this->assertSame(
            BackendActionGuard::DENIED_METHOD,
            $guard->denyReason('tl_page_translation', Request::create('/contao', $method)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    /**
     * @dataProvider writeMethods
     */
    public function testWriteMethodsPassTheTransportCheck(string $method): void
    {
        $this->assertTrue((new BackendActionGuard())->isWriteMethod(Request::create('/contao', $method)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writeMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    /** Without a request there is no verified transport, so nothing proceeds. */
    public function testAMissingRequestIsRefused(): void
    {
        $guard = new BackendActionGuard();

        $this->assertFalse($guard->isWriteMethod(null));
        $this->assertSame(BackendActionGuard::DENIED_METHOD, $guard->denyReason('tl_page_translation', null));
    }

    /**
     * Requirement 1: a missing or invalid token fails, and the transport check
     * runs first so a GET is refused before any token is even considered.
     */
    public function testAMissingTokenIsRefused(): void
    {
        $guard = new BackendActionGuard();
        $post = Request::create('/contao', 'POST');

        $this->assertFalse($guard->isTokenValid($post));
        $this->assertSame(BackendActionGuard::DENIED_TOKEN, $guard->denyReason('tl_page_translation', $post));
    }

    public function testATokenIsOnlyReadFromTheRequestBody(): void
    {
        $guard = new BackendActionGuard();

        // A token supplied in the query string is never accepted.
        $request = Request::create('/contao?REQUEST_TOKEN=abc', 'POST');

        $this->assertFalse($guard->isTokenValid($request));
    }

    /** Requirement 9: only syntactically valid table identifiers are accepted. */
    public function testInvalidTableIdentifiersAreRefused(): void
    {
        $guard = new BackendActionGuard();

        foreach (['tl_page; DROP TABLE x', 'tl-page', '', 'TL_PAGE', '../etc'] as $table) {
            $this->assertFalse($guard->hasTableAccess($table), $table);
            $this->assertFalse($guard->mayRenderControl($table), $table);
        }
    }

    /** The deny reason maps to a translated message key, never to raw detail. */
    public function testDenyReasonsMapToTranslationKeys(): void
    {
        $guard = new BackendActionGuard();

        $this->assertSame(
            'contaoMultilingualPagetreeActionMethodNotAllowed',
            $guard->messageKey(BackendActionGuard::DENIED_METHOD),
        );
        $this->assertSame(
            'contaoMultilingualPagetreeReviewInvalidToken',
            $guard->messageKey(BackendActionGuard::DENIED_TOKEN),
        );
        $this->assertSame(
            'contaoMultilingualPagetreeReviewDenied',
            $guard->messageKey(BackendActionGuard::DENIED_PERMISSION),
        );
    }

    /** Requirement 16: nothing sensitive is produced without a token manager. */
    public function testTheTokenValueIsEmptyWithoutATokenManager(): void
    {
        $this->assertSame('', (new BackendActionGuard())->tokenValue());
    }
}
