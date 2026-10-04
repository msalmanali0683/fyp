<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\ActivityLogService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    use ApiResponse;

    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        // The response is identical whether or not the email is registered,
        // so this endpoint can't be used to check which emails have accounts.
        return $this->success(null, 'If an account exists for that email, a password reset link has been sent.');
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));

                ActivityLogService::log('update', 'auth', 'Password reset via emailed link', $user->id);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error(__($status), 422, ['email' => [__($status)]]);
        }

        return $this->success(null, 'Password has been reset successfully. You can now sign in.');
    }
}
