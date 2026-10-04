<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\PhaseCertificateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PhaseCertificateController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PhaseCertificateService $certificateService,
    ) {
    }

    public function download(Request $request, Project $project, string $phase): Response|JsonResponse
    {
        try {
            $this->certificateService->assertCanDownload($request->user(), $project, $phase);
            $pdf = $this->certificateService->generatePdf($project, $phase);
            $fileName = $this->certificateService->fileName($project, $phase);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
