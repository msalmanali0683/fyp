<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    use ApiResponse;

    public function store(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => $request->password,
            'status' => 'active',
        ]);

        $user->assignRole('user');

        Auth::login($user);

        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        ActivityLogService::log('register', 'auth', 'New user registered', $user->id);

        return $this->success([
            'user' => new UserResource($user->load('roles', 'permissions')),
        ], 'Registration successful.', 201);
    }
}
