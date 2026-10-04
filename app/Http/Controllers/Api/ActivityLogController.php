<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\ProgramScopeService;
use App\Support\FypActivityLog;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    use ApiResponse;

    public function __construct(private ProgramScopeService $programScope) {}

    public function index(Request $request): JsonResponse
    {
        if (! FypActivityLog::canView($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'module' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ActivityLog::query()
            ->with(['user:id,name,email', 'project:id,title,program_id'])
            ->latest();

        $actor = $request->user();

        if (! $this->programScope->isGlobalAdmin($actor)) {
            $accessible = $this->programScope->accessibleProgramIds($actor);

            if ($accessible === []) {
                $query->whereNull('project_id');
            } elseif ($accessible !== null) {
                $query->where(function ($q) use ($accessible) {
                    $q->whereNull('project_id')
                        ->orWhereHas('project', fn ($p) => $p->whereIn('program_id', $accessible));
                });
            }
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        if (! empty($validated['module'])) {
            $query->where('module', $validated['module']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $logs = $query->paginate($validated['per_page'] ?? 20);

        return $this->success([
            'logs' => ActivityLogResource::collection($logs->items()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'modules' => ActivityLog::query()->select('module')->distinct()->orderBy('module')->pluck('module'),
        ]);
    }
}
