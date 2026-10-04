<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'return proposal to team formation']);

        foreach (['fyp-committee-member', 'fyp-committee-head', 'admin'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName])->givePermissionTo('return proposal to team formation');
        }
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'return proposal to team formation')->delete();
    }
};
