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

namespace Vtinnovations\ContaoMultilingualPagetree\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\Input;
use Symfony\Component\Routing\RouterInterface;

/**
 * The one builder for backend URLs that carry a translation context.
 *
 * Every URL goes through Symfony's router, so nothing here concatenates a query
 * string by hand and every value is encoded exactly once. Only the canonical
 * language parameter is ever generated; the retained legacy parameters are
 * accepted as input by {@see BackendLanguageContext} and are never written back
 * into a URL.
 */
final class BackendUrlBuilder
{
    /**
     * Backend parameters that identify the current operation and must survive a
     * language switch unchanged.
     *
     * Sub-operation parameters such as `key`, `mode` and `field` are
     * deliberately absent: switching the editing language must return the
     * editor to the record, not into whatever sub-view they were in.
     *
     * @var list<string>
     */
    private const PRESERVED = ['do', 'popup', 'nb', 'ref', 'pid'];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly ?ContaoCsrfTokenManager $tokens = null,
    ) {
    }

    /**
     * The URL that switches to an additional language while keeping the current
     * edit operation. The context is explicit URL state, so the resulting page
     * is reproducible, bookmarkable and safe in a second browser tab.
     *
     * @param array<string, mixed> $extra
     */
    public function forLanguage(string $table, int $id, string $language, int $rootId, array $extra = []): string
    {
        return $this->build([
            ...$extra,
            'table' => $table,
            'id' => $id,
            BackendLanguageContext::LANGUAGE_PARAMETER => BackendTranslationScope::normalize($language),
            BackendLanguageContext::ROOT_PARAMETER => $rootId,
        ]);
    }

    /**
     * The URL that returns to the source language. It does not merely omit the
     * language parameter: it rebuilds the operation from scratch, so no
     * inherited parameter can carry the additional-language context back in.
     *
     * @param array<string, mixed> $extra
     */
    public function forDefaultLanguage(string $table, int $id, array $extra = []): string
    {
        return $this->build([...$extra, 'table' => $table, 'id' => $id]);
    }

    /**
     * Adds the scope of the current request to an arbitrary backend URL
     * parameter set, so child operations, return URLs and save targets stay in
     * the same language.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function withScope(array $parameters, BackendTranslationScope $scope): array
    {
        unset(
            $parameters[BackendLanguageContext::LANGUAGE_PARAMETER],
            $parameters[BackendLanguageContext::ROOT_PARAMETER],
        );

        foreach (BackendLanguageContext::LEGACY_PARAMETERS as $legacy) {
            unset($parameters[$legacy]);
        }

        return [...$parameters, ...$scope->urlParameters()];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function build(array $parameters): string
    {
        $preserved = [];

        foreach (self::PRESERVED as $name) {
            $value = $this->currentValue($name);

            if (null !== $value) {
                $preserved[$name] = $value;
            }
        }

        $parameters = [...$preserved, ...$parameters];

        // A retained legacy parameter must never be regenerated, not even when
        // it arrived on the current request.
        foreach (BackendLanguageContext::LEGACY_PARAMETERS as $legacy) {
            unset($parameters[$legacy]);
        }

        $parameters = array_filter(
            $parameters,
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );

        $token = $this->tokenValue();

        if (null !== $token) {
            $parameters['rt'] = $token;
        }

        return $this->router->generate('contao_backend', $parameters);
    }

    private function currentValue(string $name): ?string
    {
        try {
            $value = Input::get($name);
        } catch (\Throwable) {
            return null;
        }

        return is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    private function tokenValue(): ?string
    {
        try {
            return $this->tokens?->getDefaultTokenValue();
        } catch (\Throwable) {
            return null;
        }
    }
}
