<?php

namespace App\Support;

class FypPermissions
{
    public static function groups(): array
    {
        return config('fyp.permission_groups', []);
    }

    public static function all(): array
    {
        $permissions = [];

        foreach (self::groups() as $group) {
            foreach ($group['permissions'] ?? [] as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    public static function groupFor(string $permission): ?array
    {
        foreach (self::groups() as $group) {
            if (in_array($permission, $group['permissions'] ?? [], true)) {
                return $group;
            }
        }

        return null;
    }

    public static function projectAccessPermissions(): array
    {
        return [
            'view projects',
            'view all projects',
            'view evaluators',
            'assign evaluators',
        ];
    }

    public static function userManagementPermissions(): array
    {
        return [
            'view users',
            'create users',
            'edit users',
            'delete users',
            'assign user roles',
            'assign user permissions',
        ];
    }

    public static function roleManagementPermissions(): array
    {
        return [
            'view roles',
            'create roles',
            'edit roles',
            'delete roles',
        ];
    }
}
