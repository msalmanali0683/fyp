<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class SupervisorCapacityService
{
    public function __construct(private FypSettingsService $settings)
    {
    }

    public function limitFor(User $supervisor, string $phase): int
    {
        $column = match ($phase) {
            'proposal' => 'supervision_limit_proposal',
            'phase_1' => 'supervision_limit_phase_1',
            'phase_2' => 'supervision_limit_phase_2',
            default => null,
        };

        if ($column && $supervisor->{$column} !== null) {
            return (int) $supervisor->{$column};
        }

        $defaults = $this->settings->defaultSupervisorLimits();

        return (int) ($defaults[$phase] ?? 0);
    }

    public function activeCount(User $supervisor, string $phase): int
    {
        return $supervisor->supervisedProjects()
            ->where('status', 'active')
            ->where('current_phase', $phase)
            ->count();
    }

    public function remainingCapacity(User $supervisor, string $phase): int
    {
        return max(0, $this->limitFor($supervisor, $phase) - $this->activeCount($supervisor, $phase));
    }

    public function hasCapacity(User $supervisor, string $phase, ?int $excludeProjectId = null): bool
    {
        $query = $supervisor->supervisedProjects()
            ->where('status', 'active')
            ->where('current_phase', $phase);

        if ($excludeProjectId) {
            $query->where('id', '!=', $excludeProjectId);
        }

        return $query->count() < $this->limitFor($supervisor, $phase);
    }

    public function availableSupervisors(string $phase = 'proposal', ?int $excludeProjectId = null, ?int $programId = null): Collection
    {
        return User::role('supervisor')
            ->where('status', 'active')
            ->when($programId, function ($query) use ($programId) {
                $query->where(function ($inner) use ($programId) {
                    $inner->where('program_id', $programId)
                        ->orWhereHas('programMemberships', fn ($membership) => $membership->where('program_id', $programId));
                });
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (User $supervisor) => $this->hasCapacity($supervisor, $phase, $excludeProjectId))
            ->values();
    }
}
