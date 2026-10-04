<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'respond to project queries']);

        foreach (['fyp-committee-head', 'fyp-committee-member', 'admin'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName])->givePermissionTo('respond to project queries');
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'respond to project queries')->delete();
    }
};
