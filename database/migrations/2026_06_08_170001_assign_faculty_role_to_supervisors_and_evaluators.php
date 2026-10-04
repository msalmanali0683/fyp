<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'faculty']);
        Role::firstOrCreate(['name' => 'supervisor']);
        Role::firstOrCreate(['name' => 'evaluator']);

        User::role(['supervisor', 'evaluator'])->each(function (User $user) {
            if (! $user->hasRole('faculty')) {
                $user->assignRole('faculty');
            }
        });
    }

    public function down(): void
    {
        // Role assignments are not reverted automatically.
    }
};
