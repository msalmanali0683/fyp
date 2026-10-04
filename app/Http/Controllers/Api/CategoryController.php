<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\ActivityLogService;
use App\Support\FypRoles;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $categories = Category::withCount('records')->latest()->get();

        return $this->success(CategoryResource::collection($categories));
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(FypRoles::fullRecordAccessRoles())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $category = Category::create([
            ...$validated,
            'slug' => Str::slug($validated['name']),
        ]);

        ActivityLogService::log('create', 'categories', "Created category {$category->name}");

        return $this->success(new CategoryResource($category), 'Category created.', 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        if (! $request->user()->hasAnyRole(FypRoles::fullRecordAccessRoles())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $category->update([
            ...$validated,
            'slug' => Str::slug($validated['name']),
        ]);

        ActivityLogService::log('update', 'categories', "Updated category {$category->name}");

        return $this->success(new CategoryResource($category), 'Category updated.');
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        if (! $request->user()->hasAnyRole(FypRoles::fullRecordAccessRoles())) {
            return $this->error('Unauthorized.', 403);
        }

        $category->forceDelete();
        ActivityLogService::log('delete', 'categories', "Deleted category {$category->name}");

        return $this->success(null, 'Category deleted.');
    }
}
