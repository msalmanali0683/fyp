<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SyncsUserAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\FacultyImportService;
use App\Services\ProgramScopeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    use ApiResponse;
    use SyncsUserAccess;

    public function __construct(private ProgramScopeService $programScope) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::with(['roles', 'permissions', 'programRelation.department', 'departmentRelation'])
            ->when($request->only_role === 'student', function ($q) use ($request) {
                $q->with(['ledProject', 'memberProject']);

                if ($request->filled('in_team')) {
                    $inTeam = filter_var($request->in_team, FILTER_VALIDATE_BOOLEAN);

                    if ($inTeam) {
                        $q->where(function ($inner) {
                            $inner->whereHas('ledProject')
                                ->orWhereHas('projectMemberships', fn ($membership) => $membership->activeMembership());
                        });
                    } else {
                        $q->whereDoesntHave('ledProject')
                            ->whereDoesntHave('projectMemberships', fn ($membership) => $membership->activeMembership());
                    }
                }
            })
            ->when($request->search, fn ($q) => $q->where(function ($query) use ($request) {
                $search = $request->search;
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('registration_no', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->role, fn ($q) => $q->role($request->role))
            ->when($request->only_role, fn ($q) => $q->role($request->only_role))
            ->when($request->exclude_role || $request->exclude_roles, function ($q) use ($request) {
                $roles = [];

                if ($request->exclude_role) {
                    $roles[] = $request->exclude_role;
                }

                if ($request->exclude_roles) {
                    $extra = is_array($request->exclude_roles)
                        ? $request->exclude_roles
                        : explode(',', (string) $request->exclude_roles);
                    $roles = array_merge($roles, $extra);
                }

                foreach (array_unique(array_filter(array_map('trim', $roles))) as $role) {
                    $q->whereDoesntHave('roles', fn ($r) => $r->where('name', $role));
                }
            })
            ->when($request->filled('is_proposal_enrolled'), function ($q) use ($request) {
                $q->where('is_proposal_enrolled', filter_var($request->is_proposal_enrolled, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->program_id, fn ($q) => $q->where('program_id', $request->integer('program_id')))
            ->when($request->filled('session'), function ($q) use ($request) {
                $q->whereRaw('LOWER(session) = ?', [strtolower((string) $request->session)]);
            })
            ->when(! $this->programScope->isGlobalAdmin($request->user()), function ($q) use ($request) {
                $this->programScope->scopeUsers($q, $request->user());
            })
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return $this->success([
            'users' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', Password::defaults()],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'program' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'session' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'is_proposal_enrolled' => ['nullable', 'boolean'],
        ], $this->accessRules()));

        $this->assertValidAccessPayload($validated);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => $validated['status'] ?? 'active',
            'registration_no' => $validated['registration_no'] ?? null,
            'father_name' => $validated['father_name'] ?? null,
            'program' => $validated['program'] ?? null,
            'department' => $validated['department'] ?? null,
            'session' => isset($validated['session']) && $validated['session'] !== null
                ? strtoupper(trim($validated['session']))
                : null,
            'department_id' => $validated['department_id'] ?? null,
            'program_id' => $validated['program_id'] ?? null,
            'is_proposal_enrolled' => $validated['is_proposal_enrolled'] ?? false,
        ]);

        if (! empty($validated['program_id'])) {
            $program = Program::with('department')->find($validated['program_id']);
            if ($program) {
                $user->update([
                    'department_id' => $program->department_id,
                    'program' => $program->name,
                    'department' => $program->department?->name,
                ]);
                $this->programScope->syncMembership($user, $program->id, [], true);
            }
        }

        $this->syncUserAccess($user, $validated);
        $this->programScope->syncProgramRoleMembership($user->fresh(), $this->resolveRoles($validated));

        ActivityLogService::log('create', 'users', "Created user {$user->email}");

        return $this->success([
            'user' => new UserResource($user->load(['roles', 'permissions'])),
        ], 'User created successfully.', 201);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success([
            'user' => new UserResource($user->load(['roles', 'permissions'])),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'program' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'session' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'is_proposal_enrolled' => ['nullable', 'boolean'],
        ], $this->accessRules(false)));

        if (array_key_exists('session', $validated)) {
            $validated['session'] = $validated['session'] !== null && $validated['session'] !== ''
                ? strtoupper(trim($validated['session']))
                : null;
        }

        $this->assertValidAccessPayload($validated, $user->getRoleNames()->toArray());

        DB::transaction(function () use ($user, $validated) {
            $user->update(collect($validated)->except(['roles', 'role', 'permissions'])->toArray());

            if (! empty($validated['program_id'])) {
                $program = Program::with('department')->find($validated['program_id']);
                if ($program) {
                    $user->update([
                        'department_id' => $program->department_id,
                        'program' => $program->name,
                        'department' => $program->department?->name,
                    ]);
                    $this->programScope->syncMembership($user, $program->id, [], true);
                }
            }

            $this->syncUserAccess($user, $validated);
            $this->programScope->syncProgramRoleMembership($user->fresh(), $this->resolveRoles($validated));
        });

        ActivityLogService::log('update', 'users', "Updated user {$user->email}");

        return $this->success([
            'user' => new UserResource($user->fresh()->load(['roles', 'permissions'])),
        ], 'User updated successfully.');
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->hasRole('fyp-committee-head')) {
            return $this->error('FYP Committee Head account cannot be deleted.', 403);
        }

        ActivityLogService::log('delete', 'users', "Deleted user {$user->email}");
        $user->forceDelete();

        return $this->success(null, 'User deleted successfully.');
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $user->update($validated);

        ActivityLogService::log('update', 'users', "Changed status for {$user->email} to {$validated['status']}");

        return $this->success([
            'user' => new UserResource($user->fresh()->load(['roles', 'permissions'])),
        ], 'User status updated.');
    }

    public function importFaculty(Request $request, FacultyImportService $facultyImportService): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'program_id' => ['required', 'exists:programs,id'],
        ]);

        $programId = (int) $validated['program_id'];
        $this->programScope->assertCanManageProgram($request->user(), $programId);

        $result = $facultyImportService->import($request->file('file'), $programId);

        ActivityLogService::log(
            'import',
            'users',
            "Imported {$result['created_count']} faculty member(s) from Excel."
        );

        $message = $result['created_count'] > 0
            ? "{$result['created_count']} faculty member(s) imported successfully."
            : 'No faculty members were imported.';

        if ($result['error_count'] > 0) {
            $message .= " {$result['error_count']} row(s) had errors.";
        }

        return $this->success($result, $message);
    }

    public function facultyImportTemplate(FacultyImportService $facultyImportService)
    {
        return $facultyImportService->templateResponse();
    }
}
