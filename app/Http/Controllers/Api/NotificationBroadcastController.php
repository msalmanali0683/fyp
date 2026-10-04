<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ProgramScopeService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationBroadcastController extends Controller
{
    use ApiResponse;

    public function __construct(private ProgramScopeService $programScope) {}

    public function recipients(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canSendNotifications($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $query = User::query()
            ->where('status', 'active')
            ->when($request->filled('role'), fn ($q) => $q->role((string) $request->query('role')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = (string) $request->query('search');
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });

        if (! $this->programScope->isGlobalAdmin($user)) {
            $this->programScope->scopeUsers($query, $user);
        }

        $users = $query->orderBy('name')->limit(200)->get(['id', 'name', 'email']);

        return $this->success([
            'users' => $users,
            'roles' => config('fyp.roles', []),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canSendNotifications($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'type' => ['nullable', 'in:info,success,warning,danger'],
            'recipient_mode' => ['required', 'in:users,role,all'],
            'user_ids' => ['required_if:recipient_mode,users', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role' => ['required_if:recipient_mode,role', 'string'],
        ]);

        $recipientIds = $this->resolveRecipientIds($user, $validated);

        if (empty($recipientIds)) {
            throw ValidationException::withMessages([
                'recipient_mode' => ['No matching recipients were found.'],
            ]);
        }

        NotificationService::sendToMany(
            $recipientIds,
            $validated['title'],
            $validated['message'],
            $validated['type'] ?? 'info',
            ['type' => 'broadcast'],
            $user->id
        );

        return $this->success(['recipient_count' => count($recipientIds)], 'Notification sent to '.count($recipientIds).' user(s).');
    }

    protected function resolveRecipientIds(User $actor, array $validated): array
    {
        $query = User::query()->where('status', 'active');

        if (! $this->programScope->isGlobalAdmin($actor)) {
            $this->programScope->scopeUsers($query, $actor);
        }

        return match ($validated['recipient_mode']) {
            'users' => (clone $query)->whereIn('id', $validated['user_ids'])->pluck('id')->all(),
            'role' => (clone $query)->role($validated['role'])->pluck('id')->all(),
            'all' => (clone $query)->pluck('id')->all(),
        };
    }
}
