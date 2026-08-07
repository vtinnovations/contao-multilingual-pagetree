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

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;

/** Resolves only the primary domain persisted on an exact Contao site root. */
final class RootDomainRegistry
{
    public function __construct(
        private readonly CanonicalHost $domains,
        private readonly ?ContaoFramework $framework = null,
    ) {
    }

    public function domain(int $rootId): ?string
    {
        $root = $this->root($rootId);
        if (null === $root) {
            return null;
        }

        // Contao 5 uses `dns`; `domain` is retained as a compatibility read for
        // installations whose model exposes the canonical site host that way.
        return $this->domains->normalize($root->dns ?? $root->domain ?? null);
    }

    public function isRoot(int $rootId): bool
    {
        return null !== $this->root($rootId);
    }

    /** @return list<int> */
    public function rootsForExactDomain(string $domain): array
    {
        $domain = $this->domains->normalize($domain);
        if (null === $domain || null === $this->framework) {
            return [];
        }

        try {
            $this->framework->initialize();
            $adapter = $this->framework->getAdapter(PageModel::class);
            $models = $adapter->findBy('type', 'root');
            $ids = [];
            if (null !== $models) {
                foreach ($models as $root) {
                    $candidate = $this->domains->normalize($root->dns ?? $root->domain ?? null);
                    if (null !== $candidate && $this->domains->matches($domain, $candidate)) {
                        $ids[] = (int) $root->id;
                    }
                }
            }

            return array_values(array_unique($ids));
        } catch (\Throwable) {
            return [];
        }
    }

    private function root(int $rootId): ?object
    {
        if ($rootId <= 0 || null === $this->framework) {
            return null;
        }
        try {
            $this->framework->initialize();
            $root = $this->framework->getAdapter(PageModel::class)->findByPk($rootId);

            return null !== $root && 'root' === (string) ($root->type ?? '') ? $root : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
