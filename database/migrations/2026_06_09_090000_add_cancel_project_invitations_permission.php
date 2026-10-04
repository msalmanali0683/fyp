<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'cancel project invitations']);

        foreach (['fyp-committee-member', 'fyp-committee-head', 'admin'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName])->givePermissionTo('cancel project invitations');
        }
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'cancel project invitations')->delete();
    }
};
