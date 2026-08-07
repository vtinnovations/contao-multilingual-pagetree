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

namespace Vtinnovations\ContaoMultilingualPagetree\Security;

/**
 * Why no capability is currently granted.
 *
 * These categories are safe to log and safe to show to an administrator. They
 * never reveal which verification step failed, because that would help an
 * attacker tune forged state.
 */
enum CapabilityDenial: string
{
    /** Nothing has been activated on this installation yet. */
    case NotActivated = 'not_activated';

    /** Stored state exists but is unreadable or fails its own checks. */
    case StateUnusable = 'state_unusable';

    /** This installation is not the exact host the state is bound to. */
    case HostMismatch = 'host_mismatch';

    /** The current host cannot be established from trusted data. */
    case HostUnknown = 'host_unknown';

    /** The period has not started yet. */
    case NotYetValid = 'not_yet_valid';

    /** The period ended and no free fallback is authorised. */
    case Expired = 'expired';

    /** The issuer signed a non-valid status into the document. */
    case StatusNotValid = 'status_not_valid';

    /**
     * Authentic state that predates the signed host set.
     *
     * It is preserved untouched; only a successful refresh can supply what is
     * missing, because the installation must never invent an authorised set.
     */
    case RefreshRequired = 'refresh_required';

    /**
     * Whether the situation may resolve itself without administrator action,
     * for example a worker that simply has no request context.
     */
    public function isTransient(): bool
    {
        return self::HostUnknown === $this;
    }
}
