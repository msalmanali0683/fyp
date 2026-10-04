<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProposalSessionReportResource;
use App\Http\Resources\ProposalSessionResource;
use App\Http\Resources\UserResource;
use App\Models\ProposalSession;
use App\Models\ProposalSessionReport;
use App\Models\User;
use App\Services\ProgramScopeService;
use App\Services\ProposalSessionService;
use App\Services\SessionReportService;
use App\Services\SessionStudentImportService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProposalSessionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProposalSessionService $sessionService,
        private ProgramScopeService $programScope,
        private SessionReportService $sessionReportService,
        private SessionStudentImportService $sessionStudentImportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canManageProposalSessions($user) && ! FypProposal::canGrantProposalSessionExtensions($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $this->sessionService->syncAllExpiredSubmissionStates();

        return $this->success(
            ProposalSessionResource::collection(
                $this->sessionService->listForUser($request->user())->map(function (ProposalSession $session) {
                    $session->setAttribute(
                        'students_count',
                        $this->sessionService->studentsForSessionQuery($session)->count()
                    );

                    return $session;
                })
            )
        );
    }

    public function show(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canManageProposalSessions($user) && ! FypProposal::canGrantProposalSessionExtensions($user)) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $this->programScope->canAccessProgram($user, (int) $proposalSession->program_id)) {
            return $this->error('Unauthorized.', 403);
        }

        $this->sessionService->syncSubmissionOpenState($proposalSession);

        $proposalSession->load(['program.department', 'creator']);
        $proposalSession->loadCount('projects');
        $proposalSession->setAttribute(
            'students_count',
            $this->sessionService->studentsForSessionQuery($proposalSession)->count()
        );

        $extensions = $this->groupExtensionsByProject(
            $proposalSession->extensions()
                ->with(['user:id,name,email,registration_no', 'grantedBy:id,name'])
                ->latest('id')
                ->get()
        );

        return $this->success([
            'session' => new ProposalSessionResource($proposalSession),
            'extensions' => $extensions,
            'reports' => ProposalSessionReportResource::collection(
                $this->sessionReportService->listReports($proposalSession)
            ),
        ]);
    }

    /**
     * Extensions are granted per team, not per student. Group the raw per-user
     * rows (which all share the same deadlines/reason within a project) into
     * one row per project so the admin sees "Project X — students A, B, C"
     * instead of a duplicate row for every team member.
     */
    protected function groupExtensionsByProject($extensions): Collection
    {
        return $extensions
            ->groupBy(function ($extension) {
                $project = $extension->user ? $this->sessionService->studentProjectForUser($extension->user) : null;

                return $project ? "project-{$project->id}" : "user-{$extension->user_id}";
            })
            ->map(function ($group) {
                $first = $group->first();
                $project = $first->user ? $this->sessionService->studentProjectForUser($first->user) : null;

                return [
                    'id' => $first->id,
                    'project_id' => $project?->id,
                    'project_title' => $project?->title,
                    'students' => $group->map(fn ($extension) => [
                        'id' => $extension->user?->id,
                        'name' => $extension->user?->name,
                        'registration_no' => $extension->user?->registration_no,
                    ])->values(),
                    'user_id' => $first->user_id,
                    'extended_initial_deadline' => $first->extended_initial_deadline?->toIso8601String(),
                    'extended_final_deadline' => $first->extended_final_deadline?->toIso8601String(),
                    'reason' => $first->reason,
                    'granted_by' => $first->grantedBy?->name,
                    'created_at' => $first->created_at?->toDateTimeString(),
                ];
            })
            ->values();
    }

    public function generateReports(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $this->programScope->canAccessProgram($request->user(), (int) $proposalSession->program_id)) {
            return $this->error('Unauthorized.', 403);
        }

        try {
            $reports = $this->sessionService->generateSessionReports($proposalSession, $request->user());
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success([
            'reports' => ProposalSessionReportResource::collection(
                (new \Illuminate\Database\Eloquent\Collection($reports))->load('generator:id,name')
            ),
        ], 'Session repository reports generated.');
    }

    public function downloadReport(Request $request, ProposalSession $proposalSession, ProposalSessionReport $report): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canManageProposalSessions($user) && ! FypProposal::canGrantProposalSessionExtensions($user)) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $this->programScope->canAccessProgram($user, (int) $proposalSession->program_id)) {
            return $this->error('Unauthorized.', 403);
        }

        if ((int) $report->proposal_session_id !== (int) $proposalSession->id) {
            return $this->error('Report not found for this session.', 404);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($report->file_path)) {
            return $this->error('Report file is missing from the repository.', 404);
        }

        return response()->download(
            $disk->path($report->file_path),
            $report->file_name,
            ['Content-Type' => $this->reportMimeType($report->report_type)]
        );
    }

    protected function reportMimeType(string $reportType): string
    {
        return match ($reportType) {
            ProposalSessionReport::TYPE_WORKFLOW_PDF => 'application/pdf',
            ProposalSessionReport::TYPE_STUDENTS_EXCEL,
            ProposalSessionReport::TYPE_PROJECTS_EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canSelectProposalSessionForStudents($user) && ! FypProposal::canGrantProposalSessionExtensions($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'include_all' => ['nullable', 'boolean'],
        ]);

        $programId = isset($validated['program_id']) ? (int) $validated['program_id'] : null;
        $includeAll = filter_var($validated['include_all'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($programId && ! $this->programScope->canAccessProgram($user, $programId)) {
            return $this->error('Unauthorized.', 403);
        }

        return $this->success([
            'sessions' => $this->sessionService->optionsForUser($user, $programId, $includeAll),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
        ]);

        $session = $this->sessionService->currentProposalSessionForProgram((int) $validated['program_id']);

        if (! $session) {
            return $this->success(['session' => null]);
        }

        $session->setAttribute(
            'students_count',
            $this->sessionService->studentsForSessionQuery($session)->count()
        );

        return $this->success([
            'session' => new ProposalSessionResource($session->load(['program.department', 'creator'])),
        ]);
    }

    public function students(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canManageProposalSessions($user) && ! FypProposal::canGrantProposalSessionExtensions($user)) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $this->programScope->canAccessProgram($request->user(), (int) $proposalSession->program_id)) {
            return $this->error('Unauthorized.', 403);
        }

        $students = $this->sessionService->studentsForSessionQuery($proposalSession)
            ->with(['ledProject:id,student_id,title', 'memberProject:projects.id,projects.title'])
            ->when($request->search, fn ($q) => $q->where(function ($inner) use ($request) {
                $search = $request->search;
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('registration_no', 'like', "%{$search}%")
                    ->orWhereHas('ledProject', fn ($p) => $p->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('memberProject', fn ($p) => $p->where('title', 'like', "%{$search}%"));
            }))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'students' => UserResource::collection($students),
            'meta' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
            ],
        ]);
    }

    public function exportStudents(Request $request, ProposalSession $proposalSession): StreamedResponse|JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canManageProposalSessions($user) && ! FypProposal::canGrantProposalSessionExtensions($user)) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $this->programScope->canAccessProgram($user, (int) $proposalSession->program_id)) {
            return $this->error('Unauthorized.', 403);
        }

        $filename = 'session-'.$proposalSession->code.'-students-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($proposalSession) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Name', 'Email', 'SAP ID', 'Program', 'Status']);

            $this->sessionService->studentsForSessionQuery($proposalSession)
                ->with('programRelation')
                ->orderBy('name')
                ->chunk(200, function ($students) use ($handle) {
                    foreach ($students as $student) {
                        fputcsv($handle, [
                            $student->name,
                            $student->email,
                            $student->registration_no,
                            $student->programRelation?->name ?? $student->program,
                            $student->status,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function importStudents(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        try {
            $result = $this->sessionStudentImportService->import(
                $proposalSession,
                $request->file('file'),
                $request->user()
            );
        } catch (ValidationException $e) {
            return $this->error(
                collect($e->errors())->flatten()->first() ?? 'Unable to import students.',
                422,
                $e->errors()
            );
        }

        $message = "{$result['created_count']} student(s) created";
        if ($result['updated_count'] > 0) {
            $message .= ", {$result['updated_count']} updated";
        }
        $message .= '.';

        if ($result['skipped_count'] > 0) {
            $message .= " {$result['skipped_count']} already past the proposal phase were left unchanged.";
        }

        if ($result['error_count'] > 0) {
            $message .= " {$result['error_count']} row(s) had errors.";
        }

        return $this->success($result, $message);
    }

    public function importStudentsTemplate(Request $request, ProposalSession $proposalSession)
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $this->programScope->canAccessProgram($request->user(), (int) $proposalSession->program_id)) {
            return $this->error('Unauthorized.', 403);
        }

        return $this->sessionStudentImportService->templateResponse($proposalSession);
    }

    public function previewCompleteProposalPhase(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        try {
            $preview = $this->sessionService->previewCompleteProposalPhase($proposalSession);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success($preview);
    }

    public function completeProposalPhase(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        try {
            $result = $this->sessionService->completeProposalPhase($proposalSession, $request->user());
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        $session = $result['session'];
        $summary = $result['summary'];

        return $this->success([
            'session' => new ProposalSessionResource($session->load(['program.department', 'creator'])),
            'summary' => $summary,
            'reports' => ProposalSessionReportResource::collection(
                (new \Illuminate\Database\Eloquent\Collection($result['reports'] ?? []))->load('generator:id,name')
            ),
        ], sprintf(
            'Proposal phase completed. Kept %d approved project(s). %d pending project(s) and %d unplaced student(s) will carry forward into the next proposal session.',
            $summary['approved_projects_kept'],
            $summary['pending_projects_kept'],
            $summary['unplaced_students_kept'],
        ));
    }

    public function store(Request $request): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', Rule::unique('proposal_sessions')->where(fn ($q) => $q->where('program_id', $request->integer('program_id')))],
            'initial_draft_deadline' => ['nullable', 'date'],
            'final_lock_deadline' => ['nullable', 'date', 'after_or_equal:initial_draft_deadline'],
            'is_submission_open' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->programScope->assertCanManageProgram($request->user(), (int) $validated['program_id']);
            $session = $this->sessionService->createSession($request->user(), $validated);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProposalSessionResource($session->load(['program.department', 'creator'])), 'Proposal session created.', 201);
    }

    public function update(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $this->programScope->canAccessProgram($request->user(), (int) $proposalSession->program_id)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:30', Rule::unique('proposal_sessions')->where(fn ($q) => $q->where('program_id', $proposalSession->program_id))->ignore($proposalSession->id)],
            'initial_draft_deadline' => ['nullable', 'date'],
            'final_lock_deadline' => ['nullable', 'date'],
            'phase_1_initial_deadline' => ['nullable', 'date'],
            'phase_1_final_lock_deadline' => ['nullable', 'date', 'after_or_equal:phase_1_initial_deadline'],
            'phase_2_initial_deadline' => ['nullable', 'date'],
            'phase_2_final_lock_deadline' => ['nullable', 'date', 'after_or_equal:phase_2_initial_deadline'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
        ]);

        try {
            $session = $this->sessionService->updateSession($request->user(), $proposalSession, $validated);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProposalSessionResource($session->load(['program.department', 'creator'])), 'Proposal session updated.');
    }

    public function openSubmissions(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        return $this->toggleSubmission($request, $proposalSession, true);
    }

    public function closeSubmissions(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        return $this->toggleSubmission($request, $proposalSession, false);
    }

    protected function toggleSubmission(Request $request, ProposalSession $proposalSession, bool $open): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $this->programScope->canAccessProgram($request->user(), (int) $proposalSession->program_id)) {
            return $this->error('Unauthorized.', 403);
        }

        try {
            $session = $this->sessionService->setSubmissionOpen($proposalSession, $open);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(
            new ProposalSessionResource($session->load(['program.department', 'creator'])),
            $open ? 'Proposal submissions opened.' : 'Proposal submissions closed.'
        );
    }

    public function lockAll(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $session = $this->sessionService->setFullyLocked($proposalSession, true);

        return $this->success(new ProposalSessionResource($session), 'All proposal submissions locked.');
    }

    public function unlockAll(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $session = $this->sessionService->setFullyLocked($proposalSession, false);

        return $this->success(new ProposalSessionResource($session), 'Proposal submission lock removed.');
    }

    public function extendDeadlines(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'initial_draft_deadline' => ['nullable', 'date'],
            'final_lock_deadline' => ['nullable', 'date'],
        ]);

        $session = $this->sessionService->extendDeadlines($proposalSession, $validated);

        return $this->success(new ProposalSessionResource($session), 'Session deadlines updated.');
    }

    public function grantExtension(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canGrantProposalSessionExtensions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'extended_initial_deadline' => ['nullable', 'date'],
            'extended_final_deadline' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = User::findOrFail($validated['user_id']);

        try {
            $extensions = $this->sessionService->grantUserExtension(
                $request->user(),
                $proposalSession,
                $student,
                $validated
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        $first = $extensions->first();
        $message = $extensions->count() > 1
            ? "Extension granted to the whole team ({$extensions->count()} students)."
            : 'Extension granted.';

        return $this->success([
            'extension' => [
                'user' => new UserResource($student),
                'team_size' => $extensions->count(),
                'extended_initial_deadline' => $first?->extended_initial_deadline?->toDateTimeString(),
                'extended_final_deadline' => $first?->extended_final_deadline?->toDateTimeString(),
                'reason' => $first?->reason,
            ],
        ], $message);
    }

    public function revokeExtension(Request $request, ProposalSession $proposalSession, User $user): JsonResponse
    {
        if (! FypProposal::canGrantProposalSessionExtensions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $this->sessionService->revokeUserExtension($proposalSession, $user);

        return $this->success(null, 'Individual extension removed.');
    }

    public function completePhase1(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        try {
            $result = $this->sessionService->completePhase1($proposalSession, $request->user());
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        $summary = $result['summary'];

        return $this->success([
            'session' => new ProposalSessionResource($result['session']->load(['program.department', 'creator'])),
            'summary' => $summary,
            'reports' => ProposalSessionReportResource::collection(
                (new \Illuminate\Database\Eloquent\Collection($result['reports'] ?? []))->load('generator:id,name')
            ),
        ], sprintf(
            'Phase 1 completed. Advanced %d approved project(s) to Phase 2. %d project(s) remain pending for a future session.',
            $summary['phase_1_approved_advanced'],
            $summary['phase_1_pending_kept'],
        ));
    }

    public function completePhase2(Request $request, ProposalSession $proposalSession): JsonResponse
    {
        if (! FypProposal::canManageProposalSessions($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        try {
            $result = $this->sessionService->completePhase2($proposalSession, $request->user());
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        $summary = $result['summary'];

        return $this->success([
            'session' => new ProposalSessionResource($result['session']->load(['program.department', 'creator'])),
            'summary' => $summary,
            'reports' => ProposalSessionReportResource::collection(
                (new \Illuminate\Database\Eloquent\Collection($result['reports'] ?? []))->load('generator:id,name')
            ),
        ], sprintf(
            'Phase 2 completed. Marked %d project(s) as completed. %d project(s) remain pending for a future session.',
            $summary['phase_2_approved_completed'],
            $summary['phase_2_pending_kept'],
        ));
    }

    public function studentContext(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('student')) {
            return $this->error('Unauthorized.', 403);
        }

        return $this->success($this->sessionService->studentPortalContext($request->user()));
    }
}
