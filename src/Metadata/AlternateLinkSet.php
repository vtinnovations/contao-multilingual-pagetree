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

namespace Vtinnovations\ContaoMultilingualPagetree\Metadata;

/**
 * Collects alternate links and keeps them free of duplicates.
 *
 * Two URLs are considered the same when they only differ in a trailing slash,
 * percent encoding, query-parameter order or an empty query string. The first
 * entry for a language and the first entry for a URL win, so a repeated
 * listener or an obsolete alias variant can never emit a second tag.
 */
final class AlternateLinkSet
{
    /** @var array<string, string> */
    private array $byLanguage = [];

    /** @var array<string, true> */
    private array $seenUrls = [];

    public function add(string $hreflang, string $url): bool
    {
        if ('' === $hreflang || '' === $url) {
            return false;
        }

        if (isset($this->byLanguage[$hreflang])) {
            return false;
        }

        $key = $this->normalise($url);

        if (isset($this->seenUrls[$key])) {
            return false;
        }

        $this->byLanguage[$hreflang] = $url;
        $this->seenUrls[$key] = true;

        return true;
    }

    public function has(string $hreflang): bool
    {
        return isset($this->byLanguage[$hreflang]);
    }

    public function containsUrl(string $url): bool
    {
        return isset($this->seenUrls[$this->normalise($url)]);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->byLanguage;
    }

    public function normalise(string $url): string
    {
        $parts = parse_url($url);

        if (false === $parts) {
            return strtolower(trim($url));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        $path = '/'.ltrim($path, '/');

        if ('/' !== $path) {
            $path = rtrim($path, '/');
        }

        $query = '';

        if (isset($parts['query']) && '' !== $parts['query']) {
            parse_str($parts['query'], $parameters);
            ksort($parameters);
            $query = http_build_query($parameters);
        }

        return $scheme.'://'.$host.$port.$path.('' !== $query ? '?'.$query : '');
    }
}
