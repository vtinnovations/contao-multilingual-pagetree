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

namespace Vtinnovations\ContaoMultilingualPagetree\Storage;

use Vtinnovations\ContaoMultilingualPagetree\Helper\Clock;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;

/** Conservatively copies, but never deletes, one unambiguous legacy licence. */
final class LegacyStateAdoption
{
    public function __construct(
        private readonly FilesystemPackageStore $legacy,
        private readonly RootScopedPackageStore $scoped,
        private readonly RootDomainRegistry $roots,
        private readonly RootScope $context,
        private readonly Clock $clock,
    ) {
    }

    public function adoptIfUnambiguous(): bool
    {
        if (!$this->context->isSelected() || $this->scoped->exists()) {
            return false;
        }
        $now = $this->clock->now();
        $package = $this->legacy->load($now);
        if (null === $package || [$this->context->rootId()] !== $this->roots->rootsForExactDomain($package->document->boundHost)) {
            return false;
        }

        $this->scoped->store($package, (string) $this->context->domain(), $now);

        return null !== $this->scoped->load($now);
    }

    public function needsManualActivation(): bool
    {
        return $this->context->isSelected() && !$this->scoped->exists() && $this->legacy->exists();
    }
}
