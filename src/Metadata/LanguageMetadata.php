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
 * The canonical and alternate link metadata of the current resource.
 *
 * Exactly one canonical URL, one alternate per language and at most one
 * x-default - the collection is already deduplicated when it is built.
 */
final class LanguageMetadata
{
    /**
     * @param array<string, string> $alternates hreflang code => absolute URL
     */
    public function __construct(
        public readonly ?string $canonicalUrl,
        public readonly array $alternates,
        public readonly ?string $xDefaultUrl,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, [], null);
    }

    public function hasAlternates(): bool
    {
        return [] !== $this->alternates;
    }

    /**
     * All alternate entries including x-default, in emission order.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public function links(): array
    {
        $links = [];

        foreach ($this->alternates as $hreflang => $href) {
            $links[] = ['hreflang' => (string) $hreflang, 'href' => $href];
        }

        if (null !== $this->xDefaultUrl) {
            $links[] = ['hreflang' => 'x-default', 'href' => $this->xDefaultUrl];
        }

        return $links;
    }
}
