<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Models\Department;
use App\Models\Program;
use App\Services\ActivityLogService;
use App\Services\ProgramScopeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    use ApiResponse;

    public function __construct(private ProgramScopeService $programScope)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Program::query()
            ->with('department')
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name');

        if (! $request->boolean('all', false)) {
            $this->programScope->scopePrograms($query, $request->user());
        } elseif (! $this->programScope->isGlobalAdmin($request->user())) {
            $this->programScope->scopePrograms($query, $request->user());
        }

        return $this->success([
            'programs' => ProgramResource::collection($query->get()),
        ]);
    }

    public function accessible(Request $request): JsonResponse
    {
        return $this->success([
            'programs' => ProgramResource::collection(
                $this->programScope->accessiblePrograms($request->user())
            ),
            'is_global_admin' => $this->programScope->isGlobalAdmin($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $program = Program::create([
            'department_id' => $validated['department_id'],
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'slug' => Str::slug($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        ActivityLogService::log('create', 'programs', "Created program {$program->name}");

        return $this->success([
            'program' => new ProgramResource($program->load('department')),
        ], 'Program created successfully.', 201);
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        $this->programScope->assertCanManageProgram($request->user(), $program->id);

        $validated = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $program->update([
            'department_id' => $validated['department_id'],
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'slug' => Str::slug($validated['name']),
            'is_active' => $validated['is_active'] ?? $program->is_active,
        ]);

        ActivityLogService::log('update', 'programs', "Updated program {$program->name}");

        return $this->success([
            'program' => new ProgramResource($program->fresh()->load('department')),
        ], 'Program updated successfully.');
    }
}
