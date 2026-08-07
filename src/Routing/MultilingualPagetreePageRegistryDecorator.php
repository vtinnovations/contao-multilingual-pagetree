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

namespace Vtinnovations\ContaoMultilingualPagetree\Routing;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\Page\ContentCompositionInterface;
use Contao\CoreBundle\Routing\Page\DynamicRouteInterface;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Routing\Page\PageRoute;
use Contao\CoreBundle\Routing\Page\RouteConfig;
use Contao\PageModel;
use Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;
use Symfony\Contracts\Service\ResetInterface;

class MultilingualPagetreePageRegistryDecorator extends PageRegistry implements ResetInterface
{
    private PageRegistry $inner;
    private ContaoFramework $framework;
    private EntryPointNormalizer $entryPoints;
    private LanguageUrlResolver $languageUrlResolver;
    private ?array $cachedPrefixes = null;

    public function __construct(
        PageRegistry $inner,
        ContaoFramework $framework,
        EntryPointNormalizer $entryPoints,
        LanguageUrlResolver $languageUrlResolver,
    ) {
        $this->inner = $inner;
        $this->framework = $framework;
        $this->entryPoints = $entryPoints;
        $this->languageUrlResolver = $languageUrlResolver;
    }

    public function getRoute(PageModel $pageModel): PageRoute
    {
        try {
            $pageModel->loadDetails();
        } catch (\Throwable $e) {
            // Ignore if root page not found
        }
        return $this->inner->getRoute($pageModel);
    }

    public function getPathRegex(): array
    {
        return $this->inner->getPathRegex();
    }

    public function supportsContentComposition(PageModel $pageModel): bool
    {
        return $this->inner->supportsContentComposition($pageModel);
    }

    /**
     * Contao strips a URL prefix from the request path before it derives page
     * alias candidates, so every prefix a language URL can produce has to be
     * listed here: the language code of a record that still uses the previous
     * strategy, and the explicit entry point of a record that configures one.
     *
     * Contao's own contract makes this list installation-wide. It carries no
     * root association and is never used to decide which root or language a
     * request belongs to - that decision stays root-scoped in the language URL
     * mappings.
     */
    public function getUrlPrefixes(): array
    {
        if ($this->cachedPrefixes !== null) {
            return $this->cachedPrefixes;
        }

        $prefixes = $this->inner->getUrlPrefixes();

        try {
            $this->framework->initialize();
            $languages = MultilingualPagetreeModel::findAllPublished();
            if ($languages !== null) {
                foreach ($languages as $lang) {
                    foreach ($this->prefixesOf($lang) as $prefix) {
                        if (!in_array($prefix, $prefixes, true)) {
                            $prefixes[] = $prefix;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Database or framework might not be ready yet during early cache warmers
        }

        return $this->cachedPrefixes = array_values(array_unique($prefixes));
    }

    /**
     * @return list<string>
     */
    private function prefixesOf(object $language): array
    {
        $mapping = $this->languageUrlResolver->forLanguage(
            (int) ($language->pid ?? 0),
            (string) ($language->language ?? ''),
        );

        if (null === $mapping || $this->entryPoints->isRoot($mapping->effectiveEntryPoint)) {
            return [];
        }

        return [ltrim($mapping->effectiveEntryPoint, '/')];
    }

    public function getUrlSuffixes(): array
    {
        return $this->inner->getUrlSuffixes();
    }

    public function add(string $type, RouteConfig $config, DynamicRouteInterface|null $routeEnhancer = null, ContentCompositionInterface|bool $contentComposition = true): PageRegistry
    {
        $this->inner->add($type, $config, $routeEnhancer, $contentComposition);
        $this->cachedPrefixes = null;
        return $this;
    }

    public function remove(string $type): PageRegistry
    {
        $this->inner->remove($type);
        $this->cachedPrefixes = null;
        return $this;
    }

    public function keys(): array
    {
        return $this->inner->keys();
    }

    public function isRoutable(PageModel $page): bool
    {
        return $this->inner->isRoutable($page);
    }

    public function getUnroutableTypes(): array
    {
        return $this->inner->getUnroutableTypes();
    }

    public function reset(): void
    {
        $this->inner->reset();
        $this->cachedPrefixes = null;
    }
}
