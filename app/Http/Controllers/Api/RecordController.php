<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Record;
use App\Services\ActivityLogService;
use App\Support\FypRoles;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecordController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $records = Record::with(['user', 'category'])
            ->when(! $user->hasAnyRole(FypRoles::fullRecordAccessRoles()), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->search, fn ($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return $this->success([
            'records' => RecordResource::collection($records),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'record_date' => ['required', 'date'],
        ]);

        $record = Record::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'status' => 'pending',
        ]);

        ActivityLogService::log('create', 'records', "Created record {$record->title}");

        return $this->success(new RecordResource($record->load(['user', 'category'])), 'Record created.', 201);
    }

    public function show(Request $request, Record $record): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyRole(FypRoles::fullRecordAccessRoles()) && $record->user_id !== $user->id) {
            return $this->error('Unauthorized.', 403);
        }

        return $this->success(new RecordResource($record->load(['user', 'category'])));
    }

    public function update(Request $request, Record $record): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyRole(FypRoles::fullRecordAccessRoles()) && $record->user_id !== $user->id) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'record_date' => ['required', 'date'],
        ]);

        $record->update($validated);

        ActivityLogService::log('update', 'records', "Updated record {$record->title}");

        return $this->success(new RecordResource($record->fresh()->load(['user', 'category'])), 'Record updated.');
    }

    public function destroy(Request $request, Record $record): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyRole(FypRoles::userManagementRoles()) && $record->user_id !== $user->id) {
            return $this->error('Unauthorized.', 403);
        }

        ActivityLogService::log('delete', 'records', "Deleted record {$record->title}");
        $record->forceDelete();

        return $this->success(null, 'Record deleted.');
    }

    public function updateStatus(Request $request, Record $record): JsonResponse
    {
        if (! $request->user()->hasAnyRole(FypRoles::fullRecordAccessRoles())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected', 'completed'])],
        ]);

        $record->update($validated);

        ActivityLogService::log('update', 'records', "Changed record status to {$validated['status']}");

        return $this->success(new RecordResource($record->fresh()->load(['user', 'category'])), 'Status updated.');
    }
}
