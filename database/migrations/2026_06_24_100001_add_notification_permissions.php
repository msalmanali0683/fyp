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

        Permission::firstOrCreate(['name' => 'send notifications']);
        Permission::firstOrCreate(['name' => 'delete notifications']);

        foreach (['fyp-committee-head', 'admin'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName])->givePermissionTo(['send notifications', 'delete notifications']);
        }

        foreach (['supervisor', 'fyp-committee-member'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName])->givePermissionTo('send notifications');
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::whereIn('name', ['send notifications', 'delete notifications'])->delete();
    }
};
