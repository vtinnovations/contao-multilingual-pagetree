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

namespace Vtinnovations\ContaoMultilingualPagetree\Packaging;

/**
 * A fully verified package: the exact bytes, the signed seal that vouches for
 * them and the parsed document.
 *
 * Only {@see PackageReader} creates this object, and only after every signature,
 * digest and schema check has passed. A holder may therefore treat it as
 * trusted - except for the host binding, which depends on the installation and
 * is checked by whoever knows the trusted host.
 */
final class SealedPackage
{
    public function __construct(
        public readonly string $bytes,
        public readonly PackageSeal $seal,
        public readonly PackageDocument $document,
    ) {
    }

    /** The seal as stored next to the document bytes. */
    public function sealJson(): string
    {
        return json_encode($this->seal->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** A digest of the exact bytes plus seal, used to compare two packages. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->bytes."\0".$this->sealJson());
    }
}
