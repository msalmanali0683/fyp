<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Services\ActivityLogService;
use App\Support\FypRoles;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->withCount('permissions')->orderBy('name')->get();

        return $this->success(RoleResource::collection($roles));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);
        $role->syncPermissions($validated['permissions'] ?? []);

        ActivityLogService::log('create', 'roles', "Created role {$role->name}");

        return $this->success(new RoleResource($role->load('permissions')), 'Role created.', 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'fyp-committee-head') {
            return $this->error('FYP Committee Head role cannot be modified.', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions'] ?? []);

        ActivityLogService::log('update', 'roles', "Updated role {$role->name}");

        return $this->success(new RoleResource($role->fresh()->load('permissions')), 'Role updated.');
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, FypRoles::systemRoles(), true)) {
            return $this->error('System roles cannot be deleted.', 403);
        }

        ActivityLogService::log('delete', 'roles', "Deleted role {$role->name}");
        $role->forceDelete();

        return $this->success(null, 'Role deleted.');
    }
}
