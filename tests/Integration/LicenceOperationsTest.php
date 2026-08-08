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
 * The four explicit operations - activate, replace, refresh, verify - plus
 * removal, checked as a shape rather than as behaviour.
 *
 * These assertions run without a Contao runtime on purpose: they pin the
 * properties that must survive any future refactoring of the controller, namely
 * that every operation is separately routed, separately authorised, separately
 * audited, and that the one operation advertised as local really does stay
 * local.
 */
final class LicenceOperationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** The five operations this product exposes. */
    private const OPERATIONS = ['activate', 'replace', 'refresh', 'verify', 'remove'];

    private function controller(): string
    {
        return (string) file_get_contents(self::ROOT.'/src/Controller/Backend/LicenseController.php');
    }

    private function routes(): string
    {
        return (string) file_get_contents(self::ROOT.'/src/Resources/config/routes.yaml');
    }

    public function testEveryOperationHasItsOwnActionAndItsOwnRoute(): void
    {
        $controller = $this->controller();
        $routes = $this->routes();

        foreach (self::OPERATIONS as $operation) {
            self::assertMatchesRegularExpression(
                '/public function '.$operation.'\(Request \$request, int \$rootId\): RedirectResponse/',
                $controller,
                $operation.' must be its own controller action.',
            );

            self::assertStringContainsString(
                'contao_multilingual_pagetree_root_licence_'.$operation.':',
                $routes,
                $operation.' must have its own route.',
            );
        }
    }

    public function testEveryWriteRouteIsPostOnlyRootScopedAndBackendScoped(): void
    {
        $routes = $this->routes();

        foreach (self::OPERATIONS as $operation) {
            self::assertMatchesRegularExpression(
                '/root_licence_'.$operation.":[\s\S]*?path: [^\n]*\{rootId\}[^\n]*[\s\S]*?methods: \[POST\][\s\S]*?_scope: backend/",
                $routes,
                $operation.' must be POST-only, root-scoped and backend-scoped.',
            );
        }
    }

    public function testEveryOperationPassesTheCentralGuardBeforeDoingAnything(): void
    {
        $controller = $this->controller();

        foreach (self::OPERATIONS as $operation) {
            $body = $this->actionBody($controller, $operation);

            self::assertStringContainsString(
                'if (!$this->prepare($request, $rootId)) return $this->redirect($rootId);',
                $body,
                $operation.' must re-authorise through the central guard first.',
            );
        }
    }

    /**
     * The guard is the only place that decides authorisation, and it decides it
     * from the persisted root, never from what the browser posted.
     */
    public function testTheGuardChecksMethodTokenRootDomainAndPermission(): void
    {
        $guard = $this->actionBody($this->controller(), 'prepare');

        foreach ([
            '$this->guard->isWriteMethod($request)',
            '$this->guard->isTokenValid($request)',
            '$this->domains->isRoot($rootId)',
            '$this->permission->canManage($rootId)',
            '(int) $postedRoot !== $rootId',
            'hash_equals($domain, $displayedDomain)',
            '$this->context->select($rootId, $domain)',
        ] as $required) {
            self::assertStringContainsString($required, $guard);
        }

        // The authoritative domain comes from the root record, never from the
        // submitted field, which is only compared against it.
        self::assertStringContainsString('$domain = $this->domains->domain($rootId);', $guard);
    }

    public function testActivateRefusesARootThatAlreadyHasState(): void
    {
        $body = $this->actionBody($this->controller(), 'activate');

        self::assertStringContainsString('if ($this->stored())', $body);
        self::assertStringContainsString("'already_activated'", $body);
    }

    public function testReplaceAndVerifyRefuseARootThatHasNoState(): void
    {
        $controller = $this->controller();

        foreach (['replace', 'verify'] as $operation) {
            $body = $this->actionBody($controller, $operation);
            self::assertStringContainsString('if (!$this->stored())', $body);
            self::assertStringContainsString("'not_activated'", $body);
        }
    }

    /**
     * An unreadable store counts as "occupied", so a damaged licence is replaced
     * deliberately instead of being activated over by a stray submission.
     */
    public function testAnUnreadableStoreIsTreatedAsOccupied(): void
    {
        $body = $this->actionBody($this->controller(), 'stored');

        self::assertStringContainsString('return $this->store->exists();', $body);
        self::assertStringContainsString('return true;', $body);
    }

    /** Verify is local: it must not reach the outbound exchange at all. */
    public function testVerifyPerformsNoRemoteExchange(): void
    {
        $body = $this->actionBody($this->controller(), 'verify');

        self::assertStringNotContainsString('$this->client', $body);
        self::assertStringContainsString('$this->policy->reset();', $body);
        self::assertStringContainsString('$this->policy->decision();', $body);
    }

    /** Activate and replace both submit a key through the one verified path. */
    public function testActivateAndReplaceShareOneVerifiedExchange(): void
    {
        $controller = $this->controller();

        foreach (['activate', 'replace'] as $operation) {
            self::assertStringContainsString(
                "return \$this->submitKey(\$request, \$rootId, '".$operation."');",
                $this->actionBody($controller, $operation),
            );
        }

        $shared = $this->actionBody($controller, 'submitKey');
        self::assertStringContainsString("\$request->request->get('licence_key')", $shared);
        self::assertStringContainsString('$this->client->activate($key)', $shared);
        self::assertStringContainsString('$this->policy->reset();', $shared);
    }

    /** One audit record per operation, carrying that operation's own name. */
    public function testEveryOperationIsAuditedUnderItsOwnName(): void
    {
        $controller = $this->controller();

        foreach (self::OPERATIONS as $operation) {
            self::assertMatchesRegularExpression(
                "/'".$operation."'/",
                $this->actionBody($controller, $operation).$this->actionBody($controller, 'submitKey'),
                $operation.' must appear as its own audited operation name.',
            );
        }

        self::assertStringContainsString("'operation' => \$operation,", $controller);
    }

    /**
     * The diagnostics of an operation never carry key, packet or signature
     * material. Only the safe reference is echoed back to the operator.
     */
    public function testOperationDiagnosticsStayRedacted(): void
    {
        $controller = $this->controller();

        foreach ([
            'licence_key',
            'license_payload_b64',
            'license_md5',
            'signature',
            'nonce',
            'request_packet',
            'response_packet',
            'request_sha256',
            'response_sha256',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                "'".$forbidden."' =>",
                $controller,
                $forbidden.' must never be written to the operation log.',
            );
        }

        self::assertStringContainsString("'request_id' => \$requestId,", $controller);
    }

    /** Every operation the panel offers is also accepted by the panel script. */
    public function testThePanelAndItsScriptAgreeOnTheOperationSet(): void
    {
        $template = (string) file_get_contents(self::ROOT.'/contao/templates/be_contao_multilingual_pagetree_root_license.html.twig');
        $script = (string) file_get_contents(self::ROOT.'/public/js/root-licence-navigation.js');

        foreach (self::OPERATIONS as $operation) {
            self::assertStringContainsString('data-cmp-licence-action="'.$operation.'"', $template);
            self::assertStringContainsString($operation, $script);
        }

        self::assertMatchesRegularExpression(
            '/\^\(activate\|replace\|refresh\|verify\|remove\)\$/',
            $script,
            'The script must accept exactly the shipped operation set.',
        );

        // Still no nested form inside the surrounding tl_page form.
        self::assertStringNotContainsString('<form', strtolower($template));
    }

    /** Both shipped languages label and message every operation. */
    public function testEveryOperationIsTranslatedInEveryShippedLanguage(): void
    {
        foreach (['en', 'de'] as $language) {
            $labels = (string) file_get_contents(self::ROOT.'/contao/languages/'.$language.'/default.php');

            foreach (self::OPERATIONS as $operation) {
                self::assertMatchesRegularExpression(
                    "/'".$operation."' => '/",
                    $labels,
                    $operation.' needs a control label in '.$language.'.',
                );
            }

            foreach (['verified', 'already_activated', 'not_activated', 'state_unusable'] as $message) {
                self::assertMatchesRegularExpression(
                    "/'".$message."' => '/",
                    $labels,
                    $message.' needs a message in '.$language.'.',
                );
            }
        }
    }

    /** Extracts one method body so an assertion cannot match a neighbour. */
    private function actionBody(string $source, string $method): string
    {
        $start = strpos($source, ' function '.$method.'(');

        self::assertIsInt($start, 'The method '.$method.' must exist.');

        $rest = substr($source, $start);
        $open = strpos($rest, '{');

        self::assertIsInt($open);

        $depth = 0;

        for ($index = $open; $index < strlen($rest); ++$index) {
            if ('{' === $rest[$index]) {
                ++$depth;
            } elseif ('}' === $rest[$index]) {
                --$depth;

                if (0 === $depth) {
                    return substr($rest, $open, $index - $open + 1);
                }
            }
        }

        self::fail('The method '.$method.' is not balanced.');
    }
}
