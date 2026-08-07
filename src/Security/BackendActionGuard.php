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

namespace Vtinnovations\ContaoMultilingualPagetree\Security;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * The single gate every state-changing backend action of this bundle passes.
 *
 * It enforces three independent conditions server side, in this order:
 *
 *  1. the request uses a non-GET method, so a link, an image or a prefetch can
 *     never trigger a write;
 *  2. the request carries a valid Contao CSRF token;
 *  3. the backend user actually has access to the table.
 *
 * Hiding a button is never a guard: every caller must consult this service
 * before writing, and callers that render a control also use it to decide
 * whether the control is offered at all.
 *
 * @see \Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationReviewDca
 * @see \Vtinnovations\ContaoMultilingualPagetree\Content\ModeSwitchGuard
 */
final class BackendActionGuard
{
    public const DENIED_METHOD = 'method_not_allowed';
    public const DENIED_TOKEN = 'invalid_token';
    public const DENIED_PERMISSION = 'insufficient_permission';

    /**
     * Methods accepted for a state-changing action. GET and HEAD are
     * deliberately absent.
     *
     * @var list<string>
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly ?RequestStack $requestStack = null,
        private readonly ?ContaoCsrfTokenManager $tokenManager = null,
        private readonly ?string $tokenName = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns null when the action may proceed, or a DENIED_* reason.
     */
    public function denyReason(string $table, ?Request $request = null): ?string
    {
        $request ??= $this->requestStack?->getCurrentRequest();

        if (!$this->isWriteMethod($request)) {
            return self::DENIED_METHOD;
        }

        if (!$this->isTokenValid($request)) {
            return self::DENIED_TOKEN;
        }

        if (!$this->hasTableAccess($table)) {
            return self::DENIED_PERMISSION;
        }

        return null;
    }

    public function isAllowed(string $table, ?Request $request = null): bool
    {
        return null === $this->denyReason($table, $request);
    }

    /**
     * Whether the user could perform the action at all, ignoring the transport.
     *
     * Used to decide whether a control is rendered; the transport and token are
     * still verified when the action is actually submitted.
     */
    public function mayRenderControl(string $table): bool
    {
        return $this->hasTableAccess($table);
    }

    public function isWriteMethod(?Request $request): bool
    {
        if (null === $request) {
            // Without a request there is no verified transport, so the action
            // is refused rather than assumed safe.
            return false;
        }

        return in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true);
    }

    public function isTokenValid(?Request $request): bool
    {
        if (null === $request || null === $this->tokenManager) {
            return false;
        }

        try {
            $name = $this->tokenName ?? 'contao_csrf_token';
            $token = $request->request->get('REQUEST_TOKEN');

            if (!is_string($token) || '' === $token) {
                return false;
            }

            return $this->tokenManager->isTokenValid(new CsrfToken($name, $token));
        } catch (\Throwable $exception) {
            // A token that cannot be validated is never accepted.
            $this->logger?->warning('Contao Multilingual Pagetree: CSRF validation failed: '.$exception->getMessage());

            return false;
        }
    }

    public function hasTableAccess(string $table): bool
    {
        if (1 !== preg_match('/^[a-z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $user = BackendUser::getInstance();
        } catch (\Throwable) {
            return false;
        }

        if (true === ($user->isAdmin ?? false)) {
            return true;
        }

        try {
            return (bool) $user->hasAccess($table, 'tables');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the user may run installation-wide, elevated operations such as a
     * full integrity scan or a destructive language cleanup.
     */
    public function isElevated(): bool
    {
        try {
            return true === (BackendUser::getInstance()->isAdmin ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The hidden token field a POST control has to submit.
     */
    public function tokenValue(): string
    {
        try {
            return (string) $this->tokenManager?->getDefaultTokenValue();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Maps a deny reason to the translation key shown to the editor.
     */
    public function messageKey(string $reason): string
    {
        return match ($reason) {
            self::DENIED_TOKEN => 'contaoMultilingualPagetreeReviewInvalidToken',
            self::DENIED_METHOD => 'contaoMultilingualPagetreeActionMethodNotAllowed',
            default => 'contaoMultilingualPagetreeReviewDenied',
        };
    }
}
