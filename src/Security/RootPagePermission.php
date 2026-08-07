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

use Contao\BackendUser;

/** Native Contao authorisation for one exact website root. */
final class RootPagePermission
{
    public function canView(int $rootId): bool
    {
        return $this->authoriseCurrentUser($rootId);
    }

    public function canManage(int $rootId): bool
    {
        return $this->authoriseCurrentUser($rootId);
    }

    public function canEditField(string $table, string $field, ?object $user = null): bool
    {
        if (1 !== preg_match('/^[a-z0-9_]+$/', $table) || 1 !== preg_match('/^[A-Za-z0-9_]+$/', $field)) {
            return false;
        }

        try {
            $user ??= BackendUser::getInstance();

            return true === ($user->isAdmin ?? false) || $this->hasAccess($user, $table.'::'.$field, 'alexf');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Pure native permission evaluation used by the runtime adapter and tests. */
    public function authorise(object $user, int $rootId): bool
    {
        // The administrator bypass is explicit and always evaluated first.
        if (true === ($user->isAdmin ?? false)) {
            return $rootId >= 0;
        }

        if ($rootId <= 0 || !$this->hasAccess($user, 'page', 'modules')) {
            return false;
        }

        if (!$this->hasAccess($user, (string) $rootId, 'pagemounts')) {
            return false;
        }

        return true;
    }

    private function authoriseCurrentUser(int $rootId): bool
    {
        try {
            $user = BackendUser::getInstance();
            if (true === ($user->isAdmin ?? false)) {
                return $rootId >= 0;
            }

            return $this->authorise($user, $rootId);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasAccess(object $user, string $value, string $category): bool
    {
        try {
            return method_exists($user, 'hasAccess') && (bool) $user->hasAccess($value, $category);
        } catch (\Throwable) {
            return false;
        }
    }
}
