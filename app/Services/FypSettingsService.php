<?php

namespace App\Services;

use App\Models\FypSetting;
use Illuminate\Validation\ValidationException;

class FypSettingsService
{
    public const PROPOSAL_TEAM_LIMITS = 'proposal_team_limits';

    public const DEFAULT_SUPERVISOR_LIMITS = 'default_supervisor_limits';

    public const DEFAULT_EVALUATOR_LIMITS = 'default_evaluator_limits';

    public const EVALUATOR_VISIBILITY = 'evaluator_visibility';

    public function get(string $key, ?array $default = null): array
    {
        $setting = FypSetting::query()->find($key);

        return $setting?->value ?? ($default ?? []);
    }

    public function set(string $key, array $value): void
    {
        FypSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public function proposalTeamLimits(): array
    {
        $defaults = config('fyp.proposal', []);

        return array_merge([
            'min_members' => (int) ($defaults['min_members'] ?? 3),
            'max_members' => (int) ($defaults['max_members'] ?? 5),
            'min_evaluators' => (int) ($defaults['min_evaluators'] ?? 2),
            'max_evaluators' => (int) ($defaults['max_evaluators'] ?? 3),
        ], $this->get(self::PROPOSAL_TEAM_LIMITS));
    }

    public function defaultSupervisorLimits(): array
    {
        return array_merge([
            'proposal' => 5,
            'phase_1' => 5,
            'phase_2' => 5,
        ], $this->get(self::DEFAULT_SUPERVISOR_LIMITS));
    }

    public function updateProposalTeamLimits(array $data): array
    {
        $limits = [
            'min_members' => (int) $data['min_members'],
            'max_members' => (int) $data['max_members'],
            'min_evaluators' => (int) $data['min_evaluators'],
            'max_evaluators' => (int) $data['max_evaluators'],
        ];

        if ($limits['min_members'] < 2 || $limits['max_members'] < $limits['min_members']) {
            throw ValidationException::withMessages([
                'min_members' => ['Minimum members must be at least 2 and less than or equal to maximum members.'],
            ]);
        }

        if ($limits['min_evaluators'] < 1 || $limits['max_evaluators'] < $limits['min_evaluators']) {
            throw ValidationException::withMessages([
                'min_evaluators' => ['Minimum evaluators must be at least 1 and less than or equal to maximum evaluators.'],
            ]);
        }

        $this->set(self::PROPOSAL_TEAM_LIMITS, $limits);

        return $limits;
    }

    public function updateDefaultSupervisorLimits(array $data): array
    {
        $limits = [
            'proposal' => (int) $data['proposal'],
            'phase_1' => (int) $data['phase_1'],
            'phase_2' => (int) $data['phase_2'],
        ];

        foreach ($limits as $phase => $value) {
            if ($value < 0) {
                throw ValidationException::withMessages([
                    $phase => ['Supervision limits cannot be negative.'],
                ]);
            }
        }

        $this->set(self::DEFAULT_SUPERVISOR_LIMITS, $limits);

        return $limits;
    }

    public function defaultEvaluatorLimits(): array
    {
        return array_merge([
            'proposal' => 5,
            'phase_1' => 5,
            'phase_2' => 5,
        ], $this->get(self::DEFAULT_EVALUATOR_LIMITS));
    }

    public function updateDefaultEvaluatorLimits(array $data): array
    {
        $limits = [
            'proposal' => (int) $data['proposal'],
            'phase_1' => (int) $data['phase_1'],
            'phase_2' => (int) $data['phase_2'],
        ];

        foreach ($limits as $phase => $value) {
            if ($value < 0) {
                throw ValidationException::withMessages([
                    $phase => ['Evaluation limits cannot be negative.'],
                ]);
            }
        }

        $this->set(self::DEFAULT_EVALUATOR_LIMITS, $limits);

        return $limits;
    }

    public const EVALUATOR_VISIBILITY_PHASES = ['proposal', 'phase_1', 'phase_2'];

    /**
     * Who may see an evaluator's identity and marks/comments, and whether that changes
     * once the phase's decision is finalized ("after_decision") versus while evaluation
     * is still in progress ("during_review"). Fully admin-configurable, and set
     * independently per phase (Proposal / Phase 1 / Phase 2) — no hardcoded per-role,
     * per-phase behavior anywhere else in the codebase; every reader goes through this.
     */
    public function defaultEvaluatorVisibilityForPhase(): array
    {
        return [
            'supervisor_view_identity_during_review' => true,
            'supervisor_view_identity_after_decision' => true,
            'supervisor_view_marks_during_review' => true,
            'supervisor_view_marks_after_decision' => true,
            'student_view_identity_during_review' => false,
            'student_view_identity_after_decision' => false,
            'student_view_marks_during_review' => true,
            'student_view_marks_after_decision' => true,
        ];
    }

    public function defaultEvaluatorVisibility(): array
    {
        $perPhase = $this->defaultEvaluatorVisibilityForPhase();

        return array_fill_keys(self::EVALUATOR_VISIBILITY_PHASES, $perPhase);
    }

    public function evaluatorVisibility(): array
    {
        $defaults = $this->defaultEvaluatorVisibility();
        $stored = $this->get(self::EVALUATOR_VISIBILITY);

        foreach ($defaults as $phase => $keys) {
            $defaults[$phase] = array_merge($keys, $stored[$phase] ?? []);
        }

        return $defaults;
    }

    public function evaluatorVisibilityForPhase(string $phase): array
    {
        $all = $this->evaluatorVisibility();

        return $all[$phase] ?? $this->defaultEvaluatorVisibilityForPhase();
    }

    public function updateEvaluatorVisibility(array $data): array
    {
        // Merge onto the current effective values (stored overrides + defaults) so that
        // omitting a phase or key here leaves it unchanged, rather than silently resetting
        // it to false.
        $current = $this->evaluatorVisibility();
        $keys = array_keys($this->defaultEvaluatorVisibilityForPhase());
        $visibility = [];

        foreach (self::EVALUATOR_VISIBILITY_PHASES as $phase) {
            $visibility[$phase] = [];

            foreach ($keys as $key) {
                $visibility[$phase][$key] = array_key_exists($phase, $data) && array_key_exists($key, $data[$phase])
                    ? (bool) $data[$phase][$key]
                    : $current[$phase][$key];
            }
        }

        $this->set(self::EVALUATOR_VISIBILITY, $visibility);

        return $visibility;
    }
}
