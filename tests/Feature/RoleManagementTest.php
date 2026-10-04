<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_global_admin_can_list_roles(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_committee_head_can_list_roles(): void
    {
        $head = User::factory()->create(['status' => 'active']);
        $head->assignRole('fyp-committee-head');

        Sanctum::actingAs($head);

        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_student_cannot_list_roles(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Sanctum::actingAs($student);

        $this->getJson('/api/roles')
            ->assertForbidden();
    }
}
