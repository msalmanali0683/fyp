<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class EvaluatorCapacityService
{
    public function __construct(private FypSettingsService $settings) {}

    public function limitFor(User $evaluator, string $phase): int
    {
        $column = match ($phase) {
            'proposal' => 'evaluation_limit_proposal',
            'phase_1' => 'evaluation_limit_phase_1',
            'phase_2' => 'evaluation_limit_phase_2',
            default => null,
        };

        if ($column && $evaluator->{$column} !== null) {
            return (int) $evaluator->{$column};
        }

        $defaults = $this->settings->defaultEvaluatorLimits();

        return (int) ($defaults[$phase] ?? 0);
    }

    public function activeCount(User $evaluator, string $phase, ?int $excludeProjectId = null): int
    {
        $query = $evaluator->evaluatorAssignments()
            ->whereHas('project', function ($q) use ($phase, $excludeProjectId) {
                $q->where('status', 'active')->where('current_phase', $phase);

                if ($excludeProjectId) {
                    $q->where('id', '!=', $excludeProjectId);
                }
            });

        return $query->count();
    }

    public function remainingCapacity(User $evaluator, string $phase, ?int $excludeProjectId = null): int
    {
        return max(0, $this->limitFor($evaluator, $phase) - $this->activeCount($evaluator, $phase, $excludeProjectId));
    }

    public function hasCapacity(User $evaluator, string $phase, ?int $excludeProjectId = null): bool
    {
        return $this->remainingCapacity($evaluator, $phase, $excludeProjectId) > 0;
    }

    public function availableEvaluators(string $phase, ?int $programId = null, ?int $excludeProjectId = null): Collection
    {
        return User::role('evaluator')
            ->where('status', 'active')
            ->when($programId, function ($query) use ($programId) {
                $query->where(function ($inner) use ($programId) {
                    $inner->where('program_id', $programId)
                        ->orWhereHas('programMemberships', fn ($membership) => $membership->where('program_id', $programId));
                });
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (User $evaluator) => $this->hasCapacity($evaluator, $phase, $excludeProjectId))
            ->values();
    }
}
