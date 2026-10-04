<?php

namespace App\Support;

class FypRoles
{
    public static function all(): array
    {
        return config('fyp.roles', []);
    }

    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function label(string $slug): string
    {
        return self::all()[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }

    public static function systemRoles(): array
    {
        return config('fyp.system_roles', []);
    }

    public static function userManagementRoles(): array
    {
        return config('fyp.user_management_roles', []);
    }

    public static function roleManagementRoles(): array
    {
        return config('fyp.role_management_roles', []);
    }

    public static function dashboardAdminRoles(): array
    {
        return config('fyp.dashboard_admin_roles', []);
    }

    public static function fullRecordAccessRoles(): array
    {
        return config('fyp.full_record_access_roles', []);
    }

    public static function fullProjectAccessRoles(): array
    {
        return config('fyp.full_project_access_roles', []);
    }

    public static function fullProjectListRoles(): array
    {
        return config('fyp.full_project_list_roles', []);
    }

    public static function fullProjectListPermissions(): array
    {
        return config('fyp.full_project_list_permissions', []);
    }
}
