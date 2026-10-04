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

        Permission::firstOrCreate(['name' => 'manage evaluation questions']);

        foreach (['fyp-committee-head', 'admin'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName])->givePermissionTo('manage evaluation questions');
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'manage evaluation questions')->delete();
    }
};
