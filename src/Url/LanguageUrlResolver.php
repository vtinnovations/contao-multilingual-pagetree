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

namespace Vtinnovations\ContaoMultilingualPagetree\Url;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;
use Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel;

/**
 * The single service that turns a website root and its language records into
 * language URL mappings.
 *
 * Every part of the bundle that needs a protocol, a hostname or an entry point
 * asks this resolver: incoming request resolution, route generation, page URLs,
 * canonical metadata, hreflang, x-default, the language switcher, detail
 * switching, collision validation, cache keys and redirects. Nothing else
 * recomputes an effective value.
 *
 * The persisted language record and its owning website root are the only
 * authority. A request host, a posted value or a browser header is never used
 * to decide what a mapping is - it is only ever compared against one.
 */
class LanguageUrlResolver implements ResetInterface
{
    /** @var array<int, LanguageUrlMappingSet> */
    private array $sets = [];

    /** @var array<int, array{host: string|null, secure: bool, language: string}|null> */
    private array $roots = [];

    /** @var list<array{rootId: int, host: string}>|null */
    private ?array $rootHosts = null;

    public function __construct(
        private readonly LanguageDomainNormalizer $domains,
        private readonly EntryPointNormalizer $entryPoints,
        private readonly ?ContaoFramework $framework = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Every language mapping of one website root, memoised for the duration of
     * one request and released afterwards.
     */
    public function mappings(int $rootId): LanguageUrlMappingSet
    {
        if ($rootId <= 0) {
            return new LanguageUrlMappingSet(0, [], $this->entryPoints);
        }

        if (isset($this->sets[$rootId])) {
            return $this->sets[$rootId];
        }

        return $this->sets[$rootId] = $this->buildSet($rootId);
    }

    public function forLanguage(int $rootId, string $language): ?LanguageUrlMapping
    {
        return $this->mappings($rootId)->forLanguage($language);
    }

    /**
     * The language a frontend request resolves to inside one root, using the
     * exact request hostname and the configured entry points only.
     */
    public function resolveRequest(int $rootId, Request $request): ?LanguageUrlMapping
    {
        return $this->mappings($rootId)->match($this->requestHost($request), $this->requestPath($request));
    }

    /**
     * The effective entry point of a language, ready to be placed in front of a
     * page path. `/` for the domain root, `/de` for a prefix.
     */
    public function entryPoint(int $rootId, string $language): string
    {
        return $this->forLanguage($rootId, $language)?->effectiveEntryPoint ?? EntryPointNormalizer::ROOT;
    }

    /**
     * A page path that already carries the target language's entry point,
     * turned into the URL the target language must be linked with.
     *
     * The result is absolute as soon as the target language lives on another
     * protocol or hostname than the current request, so a link can never leak
     * the source language's hostname. Otherwise the existing relative form is
     * preserved.
     */
    public function url(?LanguageUrlMapping $mapping, string $path, ?Request $request = null, bool $forceAbsolute = false): string
    {
        $path = $this->normalisePath($path);

        if (null === $mapping) {
            return null !== $request ? $request->getBaseUrl().$path : $path;
        }

        $origin = $mapping->canonicalOrigin();

        if (null === $origin) {
            return null !== $request ? $request->getBaseUrl().$path : $path;
        }

        if (!$forceAbsolute && null !== $request && $this->isSameOrigin($mapping, $request)) {
            return $request->getBaseUrl().$path;
        }

        return $origin.(null !== $request ? $request->getBaseUrl() : '').$path;
    }

    /**
     * The absolute URL of a path in a target language, or null when the target
     * language has no known hostname and no request provides one.
     */
    public function absoluteUrl(?LanguageUrlMapping $mapping, string $path, ?Request $request = null): ?string
    {
        $path = $this->normalisePath($path);
        $origin = $mapping?->canonicalOrigin();

        if (null !== $origin) {
            return $origin.(null !== $request ? $request->getBaseUrl() : '').$path;
        }

        if (null === $request) {
            return null;
        }

        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$path;
    }

    /**
     * True when the request already uses the mapping's canonical protocol and
     * hostname.
     */
    public function isSameOrigin(LanguageUrlMapping $mapping, Request $request): bool
    {
        if (null === $mapping->effectiveHostname) {
            return true;
        }

        return $mapping->effectiveProtocol === $request->getScheme()
            && hash_equals($mapping->effectiveHostname, $this->requestHost($request) ?? '');
    }

    /**
     * Every website root that claims an exact hostname, either as its own
     * primary Contao domain or through a persisted language mapping.
     *
     * Used by the collision validator only. It never rewrites a host to a root:
     * an unknown host simply produces an empty list.
     *
     * @return list<int>
     */
    public function rootsClaimingHost(string $host): array
    {
        $host = $this->domains->normalizeOrNull($host);

        if (null === $host) {
            return [];
        }

        $ids = [];

        foreach ($this->allRootHosts() as $entry) {
            if (hash_equals($entry['host'], $host)) {
                $ids[$entry['rootId']] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * The website root that a language domain belongs to, or null.
     *
     * This is what lets Contao find a root by a hostname it does not keep in
     * `tl_page.dns`. It answers only for a hostname a *published* language
     * record of that root persists as its own domain - never for a root's own
     * primary domain, which Contao resolves itself, and never for a host no
     * record names.
     *
     * The comparison is exact and constant time: no wildcard, no suffix, no
     * parent or sibling domain. A host that two roots somehow claimed is
     * refused rather than guessed.
     */
    public function rootForLanguageHost(string $host): ?int
    {
        $host = $this->domains->normalizeOrNull($host);

        if (null === $host) {
            return null;
        }

        $owners = [];

        try {
            foreach ($this->rootIds() as $rootId) {
                // A root's own domain is Contao's business, not this bundle's.
                $primary = $this->root($rootId)['host'] ?? null;

                if (null !== $primary && hash_equals($primary, $host)) {
                    return null;
                }

                foreach ($this->mappings($rootId)->published() as $mapping) {
                    if (null !== $mapping->configuredDomain
                        && null !== $mapping->effectiveHostname
                        && hash_equals($mapping->effectiveHostname, $host)
                    ) {
                        $owners[$rootId] = true;
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not resolve the website root of language host "%s": %s',
                $host,
                $exception->getMessage(),
            ));

            return null;
        }

        // Exactly one owner, or nothing. An ambiguous host is never guessed.
        return 1 === count($owners) ? (int) array_key_first($owners) : null;
    }

    /**
     * Builds the mapping a language record *would* have with the given values,
     * without persisting anything. The collision validator uses this to compare
     * a submitted value against the stored configuration.
     */
    public function projectMapping(
        int $rootId,
        int $languageId,
        string $languageCode,
        mixed $protocol,
        mixed $domain,
        mixed $entryPoint,
        bool $published,
    ): LanguageUrlMapping {
        $root = $this->root($rootId);
        $mode = ProtocolMode::fromValue($protocol);
        $configuredDomain = $this->domains->normalize($domain);
        $configuredEntryPoint = $this->entryPoints->normalize($entryPoint);
        $isDefault = null !== $root
            && LanguageUrlMappingSet::normalizeLanguage($root['language']) === LanguageUrlMappingSet::normalizeLanguage($languageCode);

        return $this->createMapping(
            $rootId,
            $languageId,
            $languageCode,
            $mode,
            $configuredDomain,
            $configuredEntryPoint,
            $isDefault,
            $published,
            $root,
        );
    }

    /** The primary hostname Contao itself persists on the website root. */
    public function rootHostname(int $rootId): ?string
    {
        return $this->root($rootId)['host'] ?? null;
    }

    public function reset(): void
    {
        $this->sets = [];
        $this->roots = [];
        $this->rootHosts = null;
    }

    private function buildSet(int $rootId): LanguageUrlMappingSet
    {
        $root = $this->root($rootId);
        $mappings = [];
        $rootLanguage = $root['language'] ?? '';
        $seenDefault = false;

        foreach ($this->records($rootId) as $record) {
            $language = trim((string) ($record->language ?? ''));

            if ('' === $language) {
                continue;
            }

            $isDefault = '' !== $rootLanguage
                && LanguageUrlMappingSet::normalizeLanguage($rootLanguage) === LanguageUrlMappingSet::normalizeLanguage($language);
            $seenDefault = $seenDefault || $isDefault;

            $mappings[] = $this->createMapping(
                $rootId,
                (int) ($record->id ?? 0),
                $language,
                ProtocolMode::fromValue($record->urlProtocol ?? null),
                $this->domains->normalizeOrNull($record->urlDomain ?? null),
                $this->entryPoints->normalizeOrLegacy($record->urlEntryPoint ?? null),
                $isDefault,
                (bool) ($record->published ?? false),
                $root,
            );
        }

        // The website root's own language always has a mapping, even when no
        // language record represents it. Without it the default language would
        // have no canonical origin and x-default could not be built.
        if (!$seenDefault && '' !== $rootLanguage) {
            $mappings[] = $this->createMapping(
                $rootId,
                0,
                $rootLanguage,
                ProtocolMode::Inherit,
                null,
                EntryPointNormalizer::LEGACY,
                true,
                true,
                $root,
            );
        }

        return new LanguageUrlMappingSet($rootId, $mappings, $this->entryPoints);
    }

    /**
     * @param array{host: string|null, secure: bool, language: string}|null $root
     */
    private function createMapping(
        int $rootId,
        int $languageId,
        string $languageCode,
        ProtocolMode $protocol,
        ?string $configuredDomain,
        string $configuredEntryPoint,
        bool $isDefault,
        bool $published,
        ?array $root,
    ): LanguageUrlMapping {
        $effectiveProtocol = $protocol->scheme() ?? (true === ($root['secure'] ?? false) ? 'https' : 'http');
        $effectiveHostname = $configuredDomain ?? ($root['host'] ?? null);

        // An explicit value always wins, including an explicit "/".
        if (EntryPointNormalizer::LEGACY !== $configuredEntryPoint) {
            $effectiveEntryPoint = $configuredEntryPoint;
            $entryPointOrigin = EntryPointOrigin::Explicit;
        } elseif ($isDefault || null !== $configuredDomain) {
            // A language with a domain of its own is served from that domain's
            // root. Deriving its language code here would turn the
            // "https://example.ru" the editor configured into
            // "https://example.ru/ru" - a path they never asked for, and one
            // that leaves the domain root itself unrouted.
            $effectiveEntryPoint = EntryPointNormalizer::ROOT;
            $entryPointOrigin = EntryPointOrigin::DomainRoot;
        } else {
            // Neither a domain nor an entry point: the record keeps exactly the
            // URL strategy it had before these fields existed.
            $effectiveEntryPoint = '/'.ltrim(LanguageUrlMappingSet::normalizeLanguage($languageCode), '/');
            $entryPointOrigin = EntryPointOrigin::Legacy;
        }

        return new LanguageUrlMapping(
            $rootId,
            $languageId,
            $languageCode,
            $protocol,
            $configuredDomain,
            $configuredEntryPoint,
            $effectiveProtocol,
            $effectiveHostname,
            $effectiveEntryPoint,
            $isDefault,
            $published,
            $entryPointOrigin,
        );
    }

    /**
     * The website root's own URL configuration.
     *
     * It is a seam rather than a private detail so a test can supply a root
     * without a booted Contao framework - exactly like the site language
     * registry does for its records.
     *
     * @return array{host: string|null, secure: bool, language: string}|null
     */
    protected function root(int $rootId): ?array
    {
        if ($rootId <= 0) {
            return null;
        }

        if (array_key_exists($rootId, $this->roots)) {
            return $this->roots[$rootId];
        }

        $root = null;

        try {
            $this->framework?->initialize();
            $page = $this->framework?->getAdapter(PageModel::class)->findByPk($rootId);

            if (null !== $page && 'root' === (string) ($page->type ?? '')) {
                $root = [
                    'host' => $this->domains->normalizeOrNull($page->dns ?? null),
                    'secure' => (bool) ($page->useSSL ?? false),
                    'language' => (string) ($page->language ?? ''),
                ];
            }
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not read the URL configuration of website root %d: %s',
                $rootId,
                $exception->getMessage(),
            ));
        }

        return $this->roots[$rootId] = $root;
    }

    /**
     * Every language record of one root, published or not.
     *
     * @return iterable<object>
     */
    protected function records(int $rootId): iterable
    {
        try {
            $this->framework?->initialize();
            $models = $this->framework?->getAdapter(MultilingualPagetreeModel::class)->findByPid($rootId);

            return null === $models ? [] : $models;
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not read the language URL configuration of website root %d: %s',
                $rootId,
                $exception->getMessage(),
            ));

            return [];
        }
    }

    /**
     * @return list<int>
     */
    protected function rootIds(): array
    {
        $ids = [];

        try {
            $this->framework?->initialize();
            $roots = $this->framework?->getAdapter(PageModel::class)->findBy('type', 'root');

            foreach ($roots ?? [] as $root) {
                $rootId = (int) ($root->id ?? 0);

                if ($rootId > 0) {
                    $ids[$rootId] = true;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not enumerate website roots: %s',
                $exception->getMessage(),
            ));
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Every (root, exact hostname) pair the installation persists.
     *
     * @return list<array{rootId: int, host: string}>
     */
    protected function allRootHosts(): array
    {
        if (null !== $this->rootHosts) {
            return $this->rootHosts;
        }

        $hosts = [];

        try {
            foreach ($this->rootIds() as $rootId) {
                $primary = $this->root($rootId)['host'] ?? null;

                if (null !== $primary) {
                    $hosts[] = ['rootId' => $rootId, 'host' => $primary];
                }

                foreach ($this->mappings($rootId)->hostnames() as $host) {
                    $hosts[] = ['rootId' => $rootId, 'host' => $host];
                }
            }
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not collect the configured website-root hostnames: %s',
                $exception->getMessage(),
            ));
        }

        return $this->rootHosts = $hosts;
    }

    private function requestHost(Request $request): ?string
    {
        try {
            $host = strtolower(trim($request->getHost()));
        } catch (\Throwable) {
            // A host rejected by Symfony's trusted-host check never becomes a
            // mapping candidate.
            return null;
        }

        return '' === $host ? null : $host;
    }

    private function requestPath(Request $request): string
    {
        return '/'.ltrim(rawurldecode($request->getPathInfo()), '/');
    }

    private function normalisePath(string $path): string
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, null);
        $path = '/'.ltrim((string) $path, '/');
        $path = (string) preg_replace('#/{2,}#', '/', $path);

        return null === $query || '' === $query ? $path : $path.'?'.$query;
    }
}
