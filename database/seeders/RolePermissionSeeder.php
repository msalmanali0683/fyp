<?php

namespace Database\Seeders;

use App\Support\FypPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $allPermissions = FypPermissions::all();

        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Permission::query()
            ->whereNotIn('name', $allPermissions)
            ->each(fn (Permission $permission) => $permission->delete());

        $student = Role::firstOrCreate(['name' => 'student']);
        $faculty = Role::firstOrCreate(['name' => 'faculty']);
        $supervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $evaluator = Role::firstOrCreate(['name' => 'evaluator']);
        $committeeMember = Role::firstOrCreate(['name' => 'fyp-committee-member']);
        $committeeHead = Role::firstOrCreate(['name' => 'fyp-committee-head']);

        $student->syncPermissions([
            'view dashboard',
        ]);

        $faculty->syncPermissions([
            'view dashboard',
        ]);

        $supervisor->syncPermissions([
            'view dashboard',
            'view projects',
            'send notifications',
        ]);

        $admin->syncPermissions($allPermissions);

        $evaluator->syncPermissions([
            'view dashboard',
            'view projects',
        ]);

        $committeeMember->syncPermissions([
            'view dashboard',
            'view projects',
            'view all projects',
            'view evaluators',
            'assign evaluators',
            'view supervisors',
            'approve fyp',
            'committee final review',
            'view reports',
            'manage project team',
            'return proposal to team formation',
            'cancel project invitations',
            'manage supervisor invitations',
            'manage evaluator reviews',
            'respond to project queries',
            'send notifications',
        ]);

        $committeeHead->syncPermissions($allPermissions);
    }
}
