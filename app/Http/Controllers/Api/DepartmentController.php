<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\ActivityLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $departments = Department::query()
            ->withCount('programs')
            ->with(['programs' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        return $this->success([
            'departments' => DepartmentResource::collection($departments),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:departments,name'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $department = Department::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        ActivityLogService::log('create', 'departments', "Created department {$department->name}");

        return $this->success([
            'department' => new DepartmentResource($department),
        ], 'Department created successfully.', 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department->id)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $department->update([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'is_active' => $validated['is_active'] ?? $department->is_active,
        ]);

        ActivityLogService::log('update', 'departments', "Updated department {$department->name}");

        return $this->success([
            'department' => new DepartmentResource($department->fresh()),
        ], 'Department updated successfully.');
    }
}
