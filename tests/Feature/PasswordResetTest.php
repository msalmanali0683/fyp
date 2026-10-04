<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_student_can_request_a_password_reset_link(): void
    {
        Notification::fake();

        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->postJson('/api/forgot-password', ['email' => $student->email])
            ->assertOk();

        Notification::assertSentTo($student, ResetPassword::class);
    }

    public function test_forgot_password_does_not_reveal_whether_the_email_is_registered(): void
    {
        Notification::fake();

        $known = User::factory()->create(['status' => 'active']);

        $knownResponse = $this->postJson('/api/forgot-password', ['email' => $known->email]);
        $unknownResponse = $this->postJson('/api/forgot-password', ['email' => 'nobody-here@fyp.com']);

        $knownResponse->assertOk();
        $unknownResponse->assertOk();
        $this->assertSame($knownResponse->json('message'), $unknownResponse->json('message'));

        Notification::assertSentTo($known, ResetPassword::class);
        $this->assertNull(User::where('email', 'nobody-here@fyp.com')->first());
    }

    public function test_student_can_reset_password_with_a_valid_token(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $token = Password::createToken($student);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $student->email,
            'password' => 'NewPass@123',
            'password_confirmation' => 'NewPass@123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPass@123', $student->fresh()->password));
    }

    public function test_reset_password_is_rejected_with_an_invalid_token(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $originalPassword = $student->password;
        $student->assignRole('student');

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $student->email,
            'password' => 'NewPass@123',
            'password_confirmation' => 'NewPass@123',
        ])->assertStatus(422);

        $this->assertSame($originalPassword, $student->fresh()->password);
    }
}
