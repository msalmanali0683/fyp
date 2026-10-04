<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentTransferRequestResource;
use App\Models\StudentTransferRequest;
use App\Services\StudentTransferService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StudentTransferController extends Controller
{
    use ApiResponse;

    public function __construct(private StudentTransferService $transferService) {}

    public function targets(Request $request): JsonResponse
    {
        return $this->success($this->transferService->transferTargetsFor($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $transferRequest = $this->transferService->requestTransfer(
                $request->user(),
                $validated['reason']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new StudentTransferRequestResource($transferRequest), 'Transfer request submitted.', 201);
    }

    public function selectTarget(Request $request, StudentTransferRequest $transferRequest): JsonResponse
    {
        $validated = $request->validate([
            'to_project_id' => ['required', 'integer', 'exists:projects,id'],
        ]);

        try {
            $updated = $this->transferService->selectTarget(
                $request->user(),
                $transferRequest,
                (int) $validated['to_project_id']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new StudentTransferRequestResource($updated), 'Join request sent to the group leader.');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canDecideStudentTransfer($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $requests = $this->transferService->listActiveRequestsForUser($user);

        return $this->success(StudentTransferRequestResource::collection($requests));
    }

    public function decide(Request $request, StudentTransferRequest $transferRequest): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $this->transferService->decide(
                $request->user(),
                $transferRequest,
                (bool) $validated['approve'],
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new StudentTransferRequestResource($updated), 'Transfer decision recorded.');
    }

    public function cancel(Request $request, StudentTransferRequest $transferRequest): JsonResponse
    {
        try {
            $updated = $this->transferService->cancel($request->user(), $transferRequest);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new StudentTransferRequestResource($updated), 'Transfer request cancelled.');
    }
}
