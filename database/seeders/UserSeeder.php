<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $studentDefaults = [
            'program' => 'BS Computer Science',
            'department' => 'Computer Science',
            'session' => 'SP26',
            'is_proposal_enrolled' => false,
        ];

        $enrolledStudentDefaults = [
            ...$studentDefaults,
            'is_proposal_enrolled' => true,
        ];

        $accounts = [
            [
                'email' => 'student@fyp.com',
                'name' => 'Muhammad Salman Ali',
                'role' => 'student',
                'registration_no' => 'SAP-2024-001',
                'father_name' => 'Muhammad Ashiq',
                ...$enrolledStudentDefaults,
            ],
            [
                'email' => 'student2@fyp.com',
                'name' => 'Ayesha Khan',
                'role' => 'student',
                'registration_no' => 'SAP-2024-002',
                'father_name' => 'Khan Sahib',
                ...$enrolledStudentDefaults,
            ],
            [
                'email' => 'student3@fyp.com',
                'name' => 'Hassan Ahmed',
                'role' => 'student',
                'registration_no' => 'SAP-2024-003',
                'father_name' => 'Ahmed Ali',
                ...$enrolledStudentDefaults,
            ],
            [
                'email' => 'student4@fyp.com',
                'name' => 'Fatima Noor',
                'role' => 'student',
                'registration_no' => 'SAP-2024-004',
                'father_name' => 'Noor Muhammad',
                ...$studentDefaults,
            ],
            [
                'email' => 'student5@fyp.com',
                'name' => 'Usman Tariq',
                'role' => 'student',
                'registration_no' => 'SAP-2024-005',
                'father_name' => 'Tariq Mehmood',
                ...$studentDefaults,
            ],
            [
                'email' => 'student6@fyp.com',
                'name' => 'Zainab Shah',
                'role' => 'student',
                'registration_no' => 'SAP-2024-006',
                'father_name' => 'Shahid Hussain',
                ...$studentDefaults,
            ],
            [
                'email' => 'student7@fyp.com',
                'name' => 'Bilal Raza',
                'role' => 'student',
                'registration_no' => 'SAP-2024-007',
                'father_name' => 'Raza Khan',
                ...$studentDefaults,
            ],
            [
                'email' => 'student8@fyp.com',
                'name' => 'Sana Iqbal',
                'role' => 'student',
                'registration_no' => 'SAP-2024-008',
                'father_name' => 'Iqbal Ahmed',
                ...$studentDefaults,
            ],
            [
                'email' => 'student9@fyp.com',
                'name' => 'Omar Farooq',
                'role' => 'student',
                'registration_no' => 'SAP-2024-009',
                'father_name' => 'Farooq Azam',
                'program' => 'BS Software Engineering',
                'department' => 'Software Engineering',
                'session' => 'SP26',
            ],
            [
                'email' => 'student10@fyp.com',
                'name' => 'Hira Malik',
                'role' => 'student',
                'registration_no' => 'SAP-2024-010',
                'father_name' => 'Malik Saeed',
                'program' => 'BS Information Technology',
                'department' => 'Information Technology',
                'session' => 'SP26',
                'is_proposal_enrolled' => false,
            ],
            [
                'email' => 'supervisor@fyp.com',
                'name' => 'Hamid Turab Mirza',
                'role' => 'supervisor',
            ],
            [
                'email' => 'supervisor2@fyp.com',
                'name' => 'Dr. Amina Qureshi',
                'role' => 'supervisor',
            ],
            [
                'email' => 'evaluator2@fyp.com',
                'name' => 'Dr. Sara Malik',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator3@fyp.com',
                'name' => 'Prof. Imran Javed',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator4@fyp.com',
                'name' => 'Dr. Nadia Hussain',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator5@fyp.com',
                'name' => 'Prof. Kamran Siddiqui',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator6@fyp.com',
                'name' => 'Dr. Aisha Rauf',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator7@fyp.com',
                'name' => 'Prof. Tariq Mahmood',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator8@fyp.com',
                'name' => 'Dr. Sana Akram',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator9@fyp.com',
                'name' => 'Prof. Bilal Anwar',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator10@fyp.com',
                'name' => 'Dr. Hina Sheikh',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator11@fyp.com',
                'name' => 'Prof. Omar Rashid',
                'role' => 'evaluator',
            ],
            [
                'email' => 'evaluator12@fyp.com',
                'name' => 'Dr. Zainab Ali',
                'role' => 'evaluator',
            ],
            [
                'email' => 'admin@fyp.com',
                'name' => 'System Admin',
                'role' => 'admin',
            ],
            [
                'email' => 'evaluator@fyp.com',
                'name' => 'Demo Evaluator',
                'role' => 'evaluator',
            ],
            [
                'email' => 'committee@fyp.com',
                'name' => 'FYP Committee Member',
                'role' => 'fyp-committee-member',
            ],
            [
                'email' => 'committee-head@fyp.com',
                'name' => 'FYP Committee Head',
                'role' => 'fyp-committee-head',
            ],
        ];

        foreach ($accounts as $account) {
            $role = $account['role'];
            unset($account['role']);

            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    ...$account,
                    'password' => Hash::make('password'),
                    'status' => 'active',
                    'is_proposal_enrolled' => $account['is_proposal_enrolled'] ?? false,
                ]
            );

            $user->syncRoles(match ($role) {
                'supervisor' => ['faculty', 'supervisor'],
                'evaluator' => ['faculty', 'evaluator'],
                default => [$role],
            });
            $user->update(collect($account)->except('email')->toArray());
        }
    }
}
