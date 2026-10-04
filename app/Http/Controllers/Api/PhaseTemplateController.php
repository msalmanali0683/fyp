<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PhaseTemplateResource;
use App\Models\PhaseTemplate;
use App\Services\PhaseTemplateService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PhaseTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(private PhaseTemplateService $templateService) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('phase')) {
            $validated = $request->validate([
                'phase' => ['required', 'in:proposal,phase_1,phase_2'],
            ]);

            return $this->success(
                PhaseTemplateResource::collection($this->templateService->listForPhase($validated['phase']))
            );
        }

        $grouped = $this->templateService->listGroupedByPhase();

        return $this->success([
            'proposal' => PhaseTemplateResource::collection($grouped['proposal']),
            'phase_1' => PhaseTemplateResource::collection($grouped['phase_1']),
            'phase_2' => PhaseTemplateResource::collection($grouped['phase_2']),
            'can_manage' => FypProposal::canManagePhaseTemplates($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phase' => ['required', 'in:proposal,phase_1,phase_2'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file'],
        ]);

        try {
            $template = $this->templateService->upload(
                $request->user(),
                $validated['phase'],
                $request->file('file'),
                $validated['title'] ?? null,
                $validated['description'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new PhaseTemplateResource($template->load('uploader')), 'Template uploaded.', 201);
    }

    public function destroy(Request $request, PhaseTemplate $phaseTemplate): JsonResponse
    {
        try {
            $this->templateService->delete($request->user(), $phaseTemplate);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(null, 'Template deleted.');
    }
}
