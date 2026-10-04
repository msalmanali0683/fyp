<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramAccessGrant;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ProgramScopeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProgramAccessController extends Controller
{
    use ApiResponse;

    public function __construct(private ProgramScopeService $programScope)
    {
    }

    public function index(Request $request, Program $program): JsonResponse
    {
        $this->programScope->assertCanManageProgram($request->user(), $program->id);

        $grants = ProgramAccessGrant::query()
            ->with(['grantee:id,name,email', 'grantedBy:id,name,email'])
            ->where('program_id', $program->id)
            ->latest()
            ->get()
            ->map(fn (ProgramAccessGrant $grant) => [
                'id' => $grant->id,
                'program_id' => $grant->program_id,
                'grantee' => [
                    'id' => $grant->grantee?->id,
                    'name' => $grant->grantee?->name,
                    'email' => $grant->grantee?->email,
                ],
                'granted_by' => [
                    'id' => $grant->grantedBy?->id,
                    'name' => $grant->grantedBy?->name,
                ],
                'created_at' => $grant->created_at?->toDateTimeString(),
            ]);

        return $this->success(['grants' => $grants]);
    }

    public function store(Request $request, Program $program): JsonResponse
    {
        $this->programScope->assertCanGrantProgramAccess($request->user(), $program->id);

        $validated = $request->validate([
            'grantee_user_id' => ['required', 'exists:users,id'],
        ]);

        $grantee = User::findOrFail($validated['grantee_user_id']);

        if ($this->programScope->canAccessProgram($grantee, $program->id)) {
            throw ValidationException::withMessages([
                'grantee_user_id' => ['This user already has access to the program.'],
            ]);
        }

        $grant = ProgramAccessGrant::create([
            'program_id' => $program->id,
            'grantee_user_id' => $grantee->id,
            'granted_by_user_id' => $request->user()->id,
        ]);

        ActivityLogService::log(
            'create',
            'program_access_grants',
            "Granted {$grantee->email} access to {$program->name}",
            $request->user()->id
        );

        return $this->success([
            'grant' => [
                'id' => $grant->id,
                'program_id' => $grant->program_id,
                'grantee_user_id' => $grant->grantee_user_id,
            ],
        ], 'Program access granted.', 201);
    }

    public function destroy(Request $request, Program $program, ProgramAccessGrant $grant): JsonResponse
    {
        if ($grant->program_id !== $program->id) {
            return $this->error('Grant not found for this program.', 404);
        }

        $this->programScope->assertCanGrantProgramAccess($request->user(), $program->id);

        $grant->delete();

        return $this->success(null, 'Program access revoked.');
    }
}
