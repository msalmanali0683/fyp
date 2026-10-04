<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\ActivityLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        return $this->success([
            'user' => new UserResource($request->user()->load([
                'roles',
                'permissions',
                'programRelation.department',
                'departmentRelation',
                'programMemberships.program.department',
            ])),
            'accessible_programs' => \App\Http\Resources\ProgramResource::collection(
                app(\App\Services\ProgramScopeService::class)->accessiblePrograms($request->user())
            ),
            'is_global_admin' => app(\App\Services\ProgramScopeService::class)->isGlobalAdmin($request->user()),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        ActivityLogService::log('update', 'profile', 'Profile updated');

        return $this->success([
            'user' => new UserResource($user->fresh()->load('roles', 'permissions')),
        ], 'Profile updated successfully.');
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        ActivityLogService::log('update', 'profile', 'Password changed');

        return $this->success(null, 'Password updated successfully.');
    }
}
