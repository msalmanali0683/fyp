<?php

namespace Database\Seeders;

use App\Services\FypSettingsService;
use Illuminate\Database\Seeder;

class FypSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(FypSettingsService::class);
        $proposal = config('fyp.proposal', []);

        $settings->set(FypSettingsService::PROPOSAL_TEAM_LIMITS, [
            'min_members' => (int) ($proposal['min_members'] ?? 3),
            'max_members' => (int) ($proposal['max_members'] ?? 5),
            'min_evaluators' => (int) ($proposal['min_evaluators'] ?? 2),
            'max_evaluators' => (int) ($proposal['max_evaluators'] ?? 3),
        ]);

        $settings->set(FypSettingsService::DEFAULT_SUPERVISOR_LIMITS, $proposal['default_supervisor_limits'] ?? [
            'proposal' => 5,
            'phase_1' => 5,
            'phase_2' => 5,
        ]);
    }
}
