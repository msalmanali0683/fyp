<?php

namespace App\Support;

use App\Models\User;

class FypActivityLog
{
    public static function authorityRoles(): array
    {
        return config('fyp.activity_log.authority_roles', ['admin', 'fyp-committee-head']);
    }

    public static function authorityPermissions(): array
    {
        return config('fyp.activity_log.authority_permissions', ['view activity logs']);
    }

    public static function canView(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::authorityRoles())) {
            return true;
        }

        foreach (self::authorityPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
