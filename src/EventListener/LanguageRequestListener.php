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

namespace Vtinnovations\ContaoMultilingualPagetree\EventListener;

use Contao\PageModel;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailTargetUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Detail\SafeQueryParameters;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
class LanguageRequestListener
{
    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly DetailTargetUrlResolver $detailUrlResolver,
        private readonly SafeQueryParameters $safeQueryParameters,
        private readonly ?RootScope $rootLicenceContext = null,
        private readonly ?RootDomainRegistry $rootDomains = null,
        private readonly ?LanguageUrlResolver $urlResolver = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->languageHelper->isFrontendRequest() || !$request->isMethodSafe()) {
            return;
        }

        $pageModel = $request->attributes->get('pageModel');
        if (!$pageModel instanceof PageModel) {
            return;
        }

        $pageModel->loadDetails();
        $rootPageId = $pageModel->type === 'root' ? (int) $pageModel->id : (int) $pageModel->rootId;
        $rootDomain = $this->rootDomains?->domain($rootPageId);
        if (null !== $rootDomain) {
            $this->rootLicenceContext?->select($rootPageId, $rootDomain);
        }
        $availableLanguages = array_column($this->languageHelper->getAvailableLanguages($rootPageId), 'language');
        $defaultLanguage = $this->languageHelper->getDefaultLanguage($rootPageId);
        if (!in_array($defaultLanguage, $availableLanguages, true)) {
            $availableLanguages[] = $defaultLanguage;
        }

        // The legacy guard interprets any /xx path segment as a language code.
        // It stays in force only while the root still uses the previous URL
        // strategy: with a configured entry point such as /languages/de a two
        // letter segment is an ordinary page alias, not a language.
        if ($this->languageHelper->usesLegacyUrlStrategy($rootPageId)
            && $this->languageHelper->hasLanguageLikePrefix($request)
            && $this->languageHelper->getLanguagePrefix($request, $availableLanguages) === null) {
            throw new NotFoundHttpException('Unknown or disabled language prefix.');
        }

        $activeLanguage = $this->languageHelper->getActiveLanguage();
        $request->setLocale($activeLanguage);

        // Canonical protocol and hostname of the resolved language. This runs
        // only after the root and the language mapping are both deterministic.
        $canonicalRedirect = $this->canonicalOriginRedirect($request, $rootPageId, $activeLanguage)
            ?? $this->staleLanguagePrefixRedirect($request, $rootPageId, $activeLanguage);

        if (null !== $canonicalRedirect) {
            $event->setResponse(new RedirectResponse($canonicalRedirect, 301));

            return;
        }
        $detailContext = $this->detailUrlResolver->detect($request, $pageModel);

        $languageQuery = $request->query->get('language');
        $isDefaultPrefixRedirect = (bool) $request->attributes->get('_contao_multilingual_pagetree_canonical_redirect');
        if ($languageQuery !== null || $isDefaultPrefixRedirect) {
            $targetLanguage = $activeLanguage;
            if (is_string($languageQuery) && $this->languageHelper->isLanguageEnabled($languageQuery, $rootPageId)) {
                $targetLanguage = $languageQuery;
            }

            $targetUrl = $detailContext !== null
                ? $this->detailUrlResolver->resolveTargetUrl($request, $pageModel, $targetLanguage)
                : $this->normalPageTargetUrl($request, $pageModel, $targetLanguage);

            // An unavailable requested detail language cleans the legacy parameter
            // while remaining on the current canonical detail instead of dropping to the reader page.
            if ($targetUrl === null && $detailContext !== null) {
                $targetUrl = $this->detailUrlResolver->resolveTargetUrl($request, $pageModel, $activeLanguage);
            }

            // Even an orphaned/unavailable detail must never render according to
            // the legacy query parameter. Keep its path and remove unsafe/query state.
            if ($targetUrl === null && $languageQuery !== null) {
                $targetUrl = $request->getBaseUrl().$request->getPathInfo();
                $query = $this->safeQueryParameters->filter($request->query->all());
                if ($query !== []) {
                    $targetUrl .= '?'.http_build_query($query);
                }
            }

            if ($targetUrl !== null) {
                $targetPath = parse_url($targetUrl, PHP_URL_PATH) ?: '/';
                $samePath = $this->normalisePath($request->getBaseUrl().$request->getPathInfo()) === $this->normalisePath($targetPath);
                if (!$samePath || $languageQuery !== null) {
                    $event->setResponse(new RedirectResponse($targetUrl, $isDefaultPrefixRedirect ? 301 : 302));

                    return;
                }
            }
        }

        // Convert translated aliases back to the original Contao record alias for core readers.
        if ($detailContext !== null && $detailContext->sourceAlias !== '') {
            $parameters = implode('/', [$detailContext->sourceAlias, ...$detailContext->routeParameters]);
            $request->attributes->set('parameters', $parameters);
            \Contao\Input::setGet('auto_item', $detailContext->sourceAlias);
            \Contao\Input::setGet('items', $detailContext->sourceAlias);
            if ($detailContext->type === \Vtinnovations\ContaoMultilingualPagetree\Detail\DetailContext::EVENT) {
                \Contao\Input::setGet('event', $detailContext->sourceAlias);
            } elseif ($detailContext->type === \Vtinnovations\ContaoMultilingualPagetree\Detail\DetailContext::FAQ) {
                \Contao\Input::setGet('faq', $detailContext->sourceAlias);
            }
        }
    }

    private function normalPageTargetUrl(Request $request, PageModel $pageModel, string $language): ?string
    {
        $path = $this->languageHelper->getCanonicalPagePath($pageModel, $language);
        if ($path === null) {
            return null;
        }

        $rootPageId = $pageModel->type === 'root' ? (int) $pageModel->id : (int) $pageModel->rootId;
        $url = $this->urlResolver?->url($this->languageHelper->urlMapping($rootPageId, $language), $path, $request)
            ?? $request->getBaseUrl().$path;
        $query = $this->safeQueryParameters->filter($request->query->all());
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $url;
    }

    /**
     * The canonical absolute URL of the current request when it reached a
     * configured language through a non-canonical protocol or hostname.
     *
     * Nothing is redirected unless the request host is *exactly* one of the
     * hostnames this root persists, so an unknown host is never pulled into a
     * configured root. The routed path and the query string are preserved
     * unchanged, and a target identical to the current URL produces no redirect
     * at all, which is what keeps the redirect free of loops.
     */
    /**
     * Drops the language-code segment a language no longer uses.
     *
     * When a language is given a domain of its own and no entry point, it is
     * served from that domain's root - so a URL that still carries the segment
     * it occupied before, `https://example.ru/ru/page`, is stale rather than
     * canonical. Only that exact leading segment is removed; the rest of the
     * path and the query string are untouched.
     *
     * The redirect is deliberately narrow: it fires only on the language's own
     * exact hostname, only while that language is the one the request resolved
     * to, and only when the segment is really the obsolete one - so it can
     * never loop and never rewrites a page whose alias happens to look like a
     * language code on some other host.
     */
    private function staleLanguagePrefixRedirect(Request $request, int $rootPageId, string $language): ?string
    {
        $mapping = $this->urlResolver?->forLanguage($rootPageId, $language);

        if (null === $mapping || !$mapping->hasDomainRootEntryPoint()) {
            return null;
        }

        $prefix = $mapping->legacyPrefix();
        $origin = $mapping->canonicalOrigin();

        if (null === $prefix || null === $origin || !$this->urlResolver->isSameOrigin($mapping, $request)) {
            return null;
        }

        $path = '/'.ltrim(rawurldecode($request->getPathInfo()), '/');

        // Segment boundary only: "/ru" and "/ru/page" are stale, "/rugby" is a
        // page of its own.
        if ($path !== $prefix && !str_starts_with($path, $prefix.'/')) {
            return null;
        }

        $remainder = substr($path, strlen($prefix));
        $target = $origin.$request->getBaseUrl().('' === $remainder ? '/' : $remainder);
        $query = $request->getQueryString();

        if (null !== $query && '' !== $query) {
            $target .= '?'.$query;
        }

        return $target === $request->getUri() ? null : $target;
    }

    private function canonicalOriginRedirect(Request $request, int $rootPageId, string $language): ?string
    {
        if (null === $this->urlResolver) {
            return null;
        }

        $mappings = $this->urlResolver->mappings($rootPageId);

        if (!$mappings->hasCustomMapping() || !$mappings->claimsHost($request->getHost())) {
            return null;
        }

        $mapping = $mappings->forLanguage($language);

        if (null === $mapping || null === $mapping->canonicalOrigin() || $this->urlResolver->isSameOrigin($mapping, $request)) {
            return null;
        }

        // Only the origin is corrected here. A wrong path would mean the router
        // matched a different language, and that is not a canonicalisation.
        if (!hash_equals((string) $mapping->effectiveHostname, strtolower($request->getHost()))) {
            return null;
        }

        $target = $mapping->canonicalOrigin().$request->getBaseUrl().$request->getPathInfo();
        $query = $request->getQueryString();

        if (null !== $query && '' !== $query) {
            $target .= '?'.$query;
        }

        return $target === $request->getUri() ? null : $target;
    }

    private function normalisePath(string $path): string
    {
        $path = '/'.ltrim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
