<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DepartmentProgramSeeder::class,
            UserSeeder::class,
            ProgramUserSeeder::class,
            FypSettingsSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
