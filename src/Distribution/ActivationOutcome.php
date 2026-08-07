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

namespace Vtinnovations\ContaoMultilingualPagetree\Distribution;

/**
 * What happened when a verified package was offered to this installation.
 *
 * These are safe to log and safe to map to a public status; they never reveal
 * which verification step failed.
 */
enum ActivationOutcome: string
{
    /** The package was verified, stored atomically and re-read successfully. */
    case Applied = 'applied';

    /** The exact same package is already active; nothing was written. */
    case AlreadyCurrent = 'already_current';

    /** Trusted host, packet host and signed host were not exactly equal. */
    case HostMismatch = 'host_mismatch';

    /** The offered version is older than the active one; state kept. */
    case Older = 'older_version';

    /** Same version, different content: refused as a conflict. */
    case Conflict = 'version_conflict';

    /** Writing failed; the previous state is still active. */
    case StorageFailure = 'storage_failure';

    /** Another node holds the activation lock, or the ledger is unavailable. */
    case Busy = 'busy';

    public function isApplied(): bool
    {
        return self::Applied === $this;
    }

    /** Whether the installation ended up with the offered package active. */
    public function isCurrent(): bool
    {
        return self::Applied === $this || self::AlreadyCurrent === $this;
    }
}
