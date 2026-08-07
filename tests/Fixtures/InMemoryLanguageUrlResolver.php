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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageDomainNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

/**
 * A language URL resolver backed by arrays instead of the database.
 *
 * It overrides only the two seams that touch Contao - the website root and the
 * language records - so every rule under test is the production rule.
 */
final class InMemoryLanguageUrlResolver extends LanguageUrlResolver
{
    /**
     * @param array<int, array{host: string|null, secure: bool, language: string}> $roots
     * @param array<int, list<array<string, mixed>>>                               $records
     */
    public function __construct(
        private readonly array $roots,
        private readonly array $records,
    ) {
        parent::__construct(new LanguageDomainNormalizer(new CanonicalHost()), new EntryPointNormalizer());
    }

    protected function root(int $rootId): ?array
    {
        return $this->roots[$rootId] ?? null;
    }

    protected function records(int $rootId): iterable
    {
        return array_map(
            static fn (array $row): object => (object) $row,
            $this->records[$rootId] ?? [],
        );
    }

    protected function rootIds(): array
    {
        return array_map('intval', array_keys($this->roots));
    }

    protected function allRootHosts(): array
    {
        $hosts = [];

        foreach (array_keys($this->roots) as $rootId) {
            $primary = $this->roots[$rootId]['host'] ?? null;

            if (is_string($primary) && '' !== $primary) {
                $hosts[] = ['rootId' => (int) $rootId, 'host' => $primary];
            }

            foreach ($this->mappings((int) $rootId)->hostnames() as $host) {
                $hosts[] = ['rootId' => (int) $rootId, 'host' => $host];
            }
        }

        return $hosts;
    }
}
