<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DepartmentProgramSeeder extends Seeder
{
    public function run(): void
    {
        $structure = [
            'Computer Science' => [
                ['code' => 'CS', 'name' => 'Computer Science'],
                ['code' => 'DS', 'name' => 'Data Science'],
            ],
            'Software Engineering' => [
                ['code' => 'SE', 'name' => 'Software Engineering'],
                ['code' => 'AI', 'name' => 'Artificial Intelligence'],
            ],
            'Information Technology' => [
                ['code' => 'IT', 'name' => 'Information Technology'],
            ],
            'Cyber Security' => [
                ['code' => 'CYBER', 'name' => 'Cyber Security'],
            ],
        ];

        foreach ($structure as $departmentName => $programs) {
            $department = Department::firstOrCreate(
                ['slug' => Str::slug($departmentName)],
                ['name' => $departmentName, 'is_active' => true]
            );

            foreach ($programs as $programData) {
                Program::firstOrCreate(
                    ['slug' => Str::slug($programData['name'])],
                    [
                        'department_id' => $department->id,
                        'name' => $programData['name'],
                        'code' => $programData['code'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
