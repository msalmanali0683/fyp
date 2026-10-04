<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_supervisor_can_broadcast_to_specific_users(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $supervisor = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $supervisor->assignRole('supervisor');

        $recipientA = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $recipientA->assignRole('student');
        $recipientB = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $recipientB->assignRole('student');

        Sanctum::actingAs($supervisor);

        $response = $this->postJson('/api/notifications/broadcast', [
            'title' => 'Important Update',
            'message' => 'Please check the new deadline.',
            'type' => 'warning',
            'recipient_mode' => 'users',
            'user_ids' => [$recipientA->id, $recipientB->id],
        ]);

        $response->assertOk()->assertJsonPath('data.recipient_count', 2);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $recipientA->id,
            'sent_by' => $supervisor->id,
            'title' => 'Important Update',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $recipientB->id,
            'sent_by' => $supervisor->id,
        ]);
    }

    public function test_can_broadcast_by_role(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $studentA = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $studentA->assignRole('student');
        $studentB = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $studentB->assignRole('student');
        $faculty = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $faculty->assignRole('faculty');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/notifications/broadcast', [
            'title' => 'Reminder',
            'message' => 'Submit your proposal soon.',
            'recipient_mode' => 'role',
            'role' => 'student',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('notifications', ['user_id' => $studentA->id, 'title' => 'Reminder']);
        $this->assertDatabaseHas('notifications', ['user_id' => $studentB->id, 'title' => 'Reminder']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $faculty->id, 'title' => 'Reminder']);
    }

    public function test_unauthorized_user_cannot_broadcast(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Sanctum::actingAs($student);

        $this->postJson('/api/notifications/broadcast', [
            'title' => 'Hi',
            'message' => 'Hello everyone.',
            'recipient_mode' => 'all',
        ])->assertStatus(403);
    }

    public function test_user_can_delete_own_notification(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $notification = Notification::create([
            'user_id' => $student->id,
            'title' => 'Test',
            'message' => 'Test message.',
            'type' => 'info',
        ]);

        Sanctum::actingAs($student);

        $this->deleteJson("/api/notifications/{$notification->id}")->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_user_cannot_delete_others_notification_without_permission(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $owner->assignRole('student');
        $outsider = User::factory()->create(['status' => 'active']);
        $outsider->assignRole('student');

        $notification = Notification::create([
            'user_id' => $owner->id,
            'title' => 'Test',
            'message' => 'Test message.',
            'type' => 'info',
        ]);

        Sanctum::actingAs($outsider);

        $this->deleteJson("/api/notifications/{$notification->id}")->assertStatus(403);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_admin_can_delete_any_notification(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $owner->assignRole('student');
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $notification = Notification::create([
            'user_id' => $owner->id,
            'title' => 'Test',
            'message' => 'Test message.',
            'type' => 'info',
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/notifications/{$notification->id}")->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }
}
