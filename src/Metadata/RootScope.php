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

use Symfony\Contracts\Service\ResetInterface;

/** Request-local, explicitly selected licence scope. It never guesses a root. */
final class RootScope implements ResetInterface
{
    private int $rootId = 0;
    private ?string $domain = null;

    public function select(int $rootId, string $domain): void
    {
        if ($rootId <= 0 || '' === $domain) {
            throw new \InvalidArgumentException('A root licence scope needs a root ID and canonical domain.');
        }

        $this->rootId = $rootId;
        $this->domain = $domain;
    }

    public function clear(): void
    {
        $this->rootId = 0;
        $this->domain = null;
    }

    public function rootId(): int { return $this->rootId; }
    public function domain(): ?string { return $this->domain; }
    public function isSelected(): bool { return $this->rootId > 0 && null !== $this->domain; }

    public function key(): string
    {
        return $this->isSelected() ? $this->rootId.'|'.$this->domain : 'unscoped';
    }

    public function reset(): void { $this->clear(); }
}
