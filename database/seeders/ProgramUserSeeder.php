<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\User;
use App\Services\ProgramScopeService;
use Illuminate\Database\Seeder;

class ProgramUserSeeder extends Seeder
{
    public function run(): void
    {
        $programScope = app(ProgramScopeService::class);

        $programs = Program::query()->with('department')->get()->keyBy('code');

        $assignStudent = function (string $email, string $programCode) use ($programScope, $programs) {
            $user = User::where('email', $email)->first();
            $program = $programs->get($programCode);

            if ($user && $program) {
                $programScope->assignUserProgram($user, $program, true);
            }
        };

        foreach (range(1, 8) as $index) {
            $assignStudent("student{$index}@fyp.com", 'CS');
        }

        $assignStudent('student9@fyp.com', 'SE');
        $assignStudent('student10@fyp.com', 'IT');

        $assignFaculty = function (string $email, array $programCodes, array $flags = []) use ($programScope, $programs) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                return;
            }

            $primary = $programs->get($programCodes[0]);
            if ($primary) {
                $programScope->assignUserProgram($user, $primary, true);
            }

            foreach (array_slice($programCodes, 1) as $code) {
                $program = $programs->get($code);
                if ($program) {
                    $programScope->syncMembership($user, $program->id, $flags);
                }
            }

            if ($primary && ! empty($flags)) {
                $programScope->syncMembership($user, $primary->id, $flags, true);
            }
        };

        $assignFaculty('supervisor@fyp.com', ['CS']);
        $assignFaculty('supervisor2@fyp.com', ['SE', 'AI']);
        $assignFaculty('evaluator@fyp.com', ['CS']);
        $assignFaculty('evaluator2@fyp.com', ['CS'], ['is_committee_head' => true]);
        $assignFaculty('evaluator3@fyp.com', ['IT'], ['is_committee_head' => true]);
        $assignFaculty('evaluator4@fyp.com', ['CS']);
        $assignFaculty('evaluator5@fyp.com', ['SE']);
        $assignFaculty('evaluator6@fyp.com', ['DS']);
        $assignFaculty('evaluator7@fyp.com', ['AI']);
        $assignFaculty('evaluator8@fyp.com', ['CYBER']);
        $assignFaculty('evaluator9@fyp.com', ['IT']);
        $assignFaculty('evaluator10@fyp.com', ['CS']);
        $assignFaculty('evaluator11@fyp.com', ['SE']);
        $assignFaculty('evaluator12@fyp.com', ['DS']);

        $assignFaculty('committee@fyp.com', ['CS'], ['is_committee_member' => true]);
        $assignFaculty('committee-head@fyp.com', ['CS'], ['is_committee_head' => true, 'is_committee_member' => true]);
    }
}
