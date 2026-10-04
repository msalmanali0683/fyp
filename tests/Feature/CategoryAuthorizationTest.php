<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function student(): User
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $student = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $student->assignRole('student');

        return $student;
    }

    protected function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_any_authenticated_user_can_list_categories(): void
    {
        Category::create(['name' => 'General', 'slug' => 'general']);

        Sanctum::actingAs($this->student());

        $this->getJson('/api/categories')->assertOk();
    }

    public function test_student_cannot_create_a_category(): void
    {
        Sanctum::actingAs($this->student());

        $this->postJson('/api/categories', ['name' => 'Hacked'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('categories', ['name' => 'Hacked']);
    }

    public function test_student_cannot_update_a_category(): void
    {
        $category = Category::create(['name' => 'General', 'slug' => 'general']);

        Sanctum::actingAs($this->student());

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Renamed'])
            ->assertStatus(403);

        $this->assertSame('General', $category->fresh()->name);
    }

    public function test_student_cannot_delete_a_category(): void
    {
        $category = Category::create(['name' => 'General', 'slug' => 'general']);

        Sanctum::actingAs($this->student());

        $this->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_admin_can_manage_categories(): void
    {
        Sanctum::actingAs($this->admin());

        $create = $this->postJson('/api/categories', ['name' => 'Travel'])
            ->assertCreated();

        $categoryId = $create->json('data.id');

        $this->putJson("/api/categories/{$categoryId}", ['name' => 'Travel & Transport'])
            ->assertOk();

        $this->deleteJson("/api/categories/{$categoryId}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $categoryId]);
    }
}
