<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Support\FypRoles;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait SyncsUserAccess
{
    protected function accessRules(bool $rolesRequired = true): array
    {
        return [
            'roles' => [$rolesRequired ? 'required' : 'sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(FypRoles::slugs())],
            'role' => ['nullable', Rule::in(FypRoles::slugs())],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    protected function resolveRoles(array $validated): array
    {
        $roles = $validated['roles'] ?? [];

        if (empty($roles) && ! empty($validated['role'])) {
            $roles = [$validated['role']];
        }

        return array_values(array_unique($roles));
    }

    protected function assertValidAccessPayload(array $validated, array $existingRoles = []): void
    {
        $roles = $this->ensureFacultyRoles($this->resolveRoles($validated));
        $effectiveRoles = ! empty($roles) ? $roles : $existingRoles;
        $permissions = array_key_exists('permissions', $validated) ? ($validated['permissions'] ?? []) : [];

        $this->assertStudentRoleIsolated($effectiveRoles, $permissions);
    }

    protected function syncUserAccess($user, array $validated): void
    {
        $roles = $this->ensureFacultyRoles($this->resolveRoles($validated));

        if (! empty($roles)) {
            $user->syncRoles($roles);
        }

        if (array_key_exists('permissions', $validated)) {
            $user->syncPermissions($validated['permissions'] ?? []);
        }
    }

    protected function assertStudentRoleIsolated(array $roles, array $permissions): void
    {
        if (! in_array('student', $roles, true)) {
            return;
        }

        $elevatedRoles = array_values(array_diff($roles, ['student']));

        if (! empty($elevatedRoles)) {
            throw ValidationException::withMessages([
                'roles' => ['A student account cannot also hold the following role(s): '.implode(', ', $elevatedRoles).'.'],
            ]);
        }

        if (! empty($permissions)) {
            throw ValidationException::withMessages([
                'permissions' => ['Direct permissions cannot be granted to a student account.'],
            ]);
        }
    }

    protected function ensureFacultyRoles(array $roles): array
    {
        $roles = array_values(array_unique($roles));

        if (array_intersect($roles, ['supervisor', 'evaluator']) && ! in_array('faculty', $roles, true)) {
            throw ValidationException::withMessages([
                'roles' => ['Supervisors and evaluators must be faculty members.'],
            ]);
        }

        return $roles;
    }
}
