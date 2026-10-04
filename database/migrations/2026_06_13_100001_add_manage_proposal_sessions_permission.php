<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'manage proposal sessions']);
        Permission::firstOrCreate(['name' => 'grant proposal session extensions']);

        foreach (['admin', 'fyp-committee-head'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName])->givePermissionTo([
                'manage proposal sessions',
                'grant proposal session extensions',
            ]);
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'manage proposal sessions',
            'grant proposal session extensions',
        ])->delete();
    }
};
