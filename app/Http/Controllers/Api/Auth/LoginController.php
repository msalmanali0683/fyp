<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\ActivityLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    use ApiResponse;

    public function store(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            return $this->error('Invalid email or password.', 401);
        }

        $user = $request->user();

        if ($user->status !== 'active') {
            Auth::logout();

            return $this->error('Your account is inactive.', 403);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        ActivityLogService::log('login', 'auth', 'User logged in', $user->id);

        return $this->success([
            'user' => new UserResource($user->load([
                'roles',
                'permissions',
                'programRelation.department',
                'departmentRelation',
                'programMemberships.program.department',
            ])),
            'accessible_programs' => \App\Http\Resources\ProgramResource::collection(
                app(\App\Services\ProgramScopeService::class)->accessiblePrograms($user)
            ),
            'is_global_admin' => app(\App\Services\ProgramScopeService::class)->isGlobalAdmin($user),
        ], 'Login successful.');
    }

    public function destroy(Request $request): JsonResponse
    {
        ActivityLogService::log('logout', 'auth', 'User logged out');

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->success(null, 'Logged out successfully.');
    }
}
