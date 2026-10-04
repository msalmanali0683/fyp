<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramAccessGrant;
use App\Models\ProgramMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProgramScopeService
{
    public function isGlobalAdmin(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * @return array<int>|null Null means all programs (global admin).
     */
    public function accessibleProgramIds(User $user): ?array
    {
        if ($this->isGlobalAdmin($user)) {
            return null;
        }

        $ids = collect();

        if ($user->program_id) {
            $ids->push((int) $user->program_id);
        }

        $membershipIds = ProgramMembership::query()
            ->where('user_id', $user->id)
            ->pluck('program_id');

        $grantIds = ProgramAccessGrant::query()
            ->where('grantee_user_id', $user->id)
            ->pluck('program_id');

        return $ids
            ->merge($membershipIds)
            ->merge($grantIds)
            ->unique()
            ->values()
            ->all();
    }

    public function canAccessProgram(User $user, int $programId): bool
    {
        $accessible = $this->accessibleProgramIds($user);

        return $accessible === null || in_array($programId, $accessible, true);
    }

    public function canAccessProject(User $user, Project $project): bool
    {
        $programId = $this->resolveProjectProgramId($project);

        if (! $programId) {
            return $this->isGlobalAdmin($user);
        }

        return $this->canAccessProgram($user, $programId);
    }

    public function scopeProjects(Builder $query, User $user): Builder
    {
        $accessible = $this->accessibleProgramIds($user);

        if ($accessible === null) {
            return $query;
        }

        if ($accessible === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inner) use ($accessible) {
            $inner->whereIn('program_id', $accessible)
                ->orWhere(function (Builder $fallback) use ($accessible) {
                    $fallback->whereNull('program_id')
                        ->whereHas('student', fn (Builder $student) => $student->whereIn('program_id', $accessible));
                });
        });
    }

    public function resolveProjectProgramId(Project $project): ?int
    {
        if ($project->program_id) {
            return (int) $project->program_id;
        }

        if ($project->relationLoaded('student')) {
            return $project->student?->program_id ? (int) $project->student->program_id : null;
        }

        $studentProgramId = $project->student()->value('program_id');

        return $studentProgramId ? (int) $studentProgramId : null;
    }

    public function scopeUsers(Builder $query, User $user): Builder
    {
        $accessible = $this->accessibleProgramIds($user);

        if ($accessible === null) {
            return $query;
        }

        if ($accessible === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inner) use ($accessible) {
            $inner->whereIn('program_id', $accessible)
                ->orWhereHas('programMemberships', fn (Builder $membership) => $membership->whereIn('program_id', $accessible));
        });
    }

    public function scopePrograms(Builder $query, User $user): Builder
    {
        $accessible = $this->accessibleProgramIds($user);

        if ($accessible === null) {
            return $query;
        }

        if ($accessible === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $accessible);
    }

    public function accessiblePrograms(User $user): Collection
    {
        $query = Program::query()
            ->with('department')
            ->where('is_active', true)
            ->orderBy('name');

        return $this->scopePrograms($query, $user)->get();
    }

    public function programHeadIds(int $programId): array
    {
        return ProgramMembership::query()
            ->where('program_id', $programId)
            ->where('is_committee_head', true)
            ->pluck('user_id')
            ->all();
    }

    public function committeeMemberIds(int $programId): array
    {
        return ProgramMembership::query()
            ->where('program_id', $programId)
            ->where(function (Builder $query) {
                $query->where('is_committee_member', true)
                    ->orWhere('is_committee_head', true);
            })
            ->pluck('user_id')
            ->all();
    }

    public function assertCanManageProgram(User $user, int $programId): void
    {
        if ($this->isGlobalAdmin($user)) {
            return;
        }

        $isHead = ProgramMembership::query()
            ->where('user_id', $user->id)
            ->where('program_id', $programId)
            ->where('is_committee_head', true)
            ->exists();

        if (! $isHead && ! $this->canAccessProgram($user, $programId)) {
            throw ValidationException::withMessages([
                'program_id' => ['You do not have access to this program.'],
            ]);
        }
    }

    public function assertCanGrantProgramAccess(User $grantor, int $programId): void
    {
        if ($this->isGlobalAdmin($grantor)) {
            return;
        }

        $isHead = ProgramMembership::query()
            ->where('user_id', $grantor->id)
            ->where('program_id', $programId)
            ->where('is_committee_head', true)
            ->exists();

        if (! $isHead) {
            throw ValidationException::withMessages([
                'program_id' => ['Only a program committee head can grant cross-program access.'],
            ]);
        }
    }

    public function syncMembership(
        User $user,
        int $programId,
        array $flags = [],
        bool $isPrimary = false,
    ): ProgramMembership {
        return ProgramMembership::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'program_id' => $programId,
            ],
            [
                'is_committee_head' => (bool) ($flags['is_committee_head'] ?? false),
                'is_committee_member' => (bool) ($flags['is_committee_member'] ?? false),
                'is_primary' => $isPrimary,
            ]
        );
    }

    public function assignUserProgram(User $user, Program $program, bool $isPrimary = true): void
    {
        $user->update([
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'program' => $program->name,
            'department' => $program->department?->name,
        ]);

        $this->syncMembership($user, $program->id, [], $isPrimary);
    }

    public function syncProgramRoleMembership(User $user, array $roles): void
    {
        if (! $user->program_id) {
            return;
        }

        $this->syncMembership($user, (int) $user->program_id, [
            'is_committee_head' => in_array('fyp-committee-head', $roles, true),
            'is_committee_member' => in_array('fyp-committee-member', $roles, true)
                || in_array('fyp-committee-head', $roles, true),
        ], true);
    }
}
