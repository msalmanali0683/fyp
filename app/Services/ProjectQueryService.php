<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectQuery;
use App\Models\ProjectQueryMessage;
use App\Models\User;
use App\Support\FypProposal;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectQueryService
{
    public function __construct(
        private ProgramScopeService $programScope,
    ) {}

    public function queryableProjectsFor(User $actor): Collection
    {
        if ($actor->hasRole('student')) {
            $studentProject = $actor->studentProject();

            return $studentProject ? collect([$studentProject]) : collect();
        }

        if (FypProposal::canRespondProjectQueries($actor)) {
            $accessible = $this->programScope->accessibleProgramIds($actor);

            if ($accessible === []) {
                return collect();
            }

            $projectsQuery = Project::query()->with('student:id,name')->orderBy('title');

            if ($accessible !== null) {
                $projectsQuery->whereIn('program_id', $accessible);
            }

            return $projectsQuery->get();
        }

        $projects = collect();

        if ($actor->hasRole('supervisor')) {
            $projects = $projects->merge($actor->supervisedProjects);
        }

        if ($actor->hasRole('evaluator')) {
            $projects = $projects->merge(
                $actor->evaluatorAssignments()->with('project')->get()->pluck('project')->filter()
            );
        }

        return $projects->unique('id')->sortBy('title')->values();
    }

    public function createQuery(User $actor, ?int $projectId, string $subject, string $message): ProjectQuery
    {
        [$project, $role] = $this->resolveRaiserContext($actor, $projectId);

        $this->assertCanCreate($actor, $project, $role);

        return DB::transaction(function () use ($actor, $project, $role, $subject, $message) {
            $query = ProjectQuery::create([
                'project_id' => $project->id,
                'raised_by' => $actor->id,
                'raised_by_role' => $role,
                'subject' => $subject,
                'status' => 'open',
            ]);

            ProjectQueryMessage::create([
                'project_query_id' => $query->id,
                'author_id' => $actor->id,
                'author_role' => $role === 'staff' ? $this->resolveStaffRoleLabel($actor) : $role,
                'message' => $message,
            ]);

            $this->notifyOnCreate($query->fresh(['project', 'raiser']));

            ActivityLogService::log(
                'create',
                'project_queries',
                "{$actor->name} raised a query (\"{$subject}\") on \"{$project->title}\"",
                $actor->id,
                $project->id
            );

            return $query->fresh(['raiser', 'messages.author', 'project.supervisor:id,name']);
        });
    }

    public function reply(User $actor, ProjectQuery $query, string $message): ProjectQuery
    {
        $this->assertCanReply($actor, $query);

        return DB::transaction(function () use ($actor, $query, $message) {
            $role = $this->resolveActorRoleLabel($actor, $query);

            $created = ProjectQueryMessage::create([
                'project_query_id' => $query->id,
                'author_id' => $actor->id,
                'author_role' => $role,
                'message' => $message,
            ]);

            $this->applyStatusTransitionOnReply($query, $actor);

            $this->notifyOnReply($query->fresh(['project']), $created, $actor);

            ActivityLogService::log(
                'reply',
                'project_queries',
                "{$actor->name} replied to a query (\"{$query->subject}\") on \"{$query->project->title}\"",
                $actor->id,
                $query->project_id
            );

            return $query->fresh(['raiser', 'closer', 'messages.author', 'project.supervisor:id,name']);
        });
    }

    public function close(User $actor, ProjectQuery $query): ProjectQuery
    {
        $this->assertCanClose($actor, $query);

        $query->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ]);

        ActivityLogService::log(
            'close',
            'project_queries',
            "{$actor->name} closed a query (\"{$query->subject}\") on \"{$query->project->title}\"",
            $actor->id,
            $query->project_id
        );

        return $query->fresh(['raiser', 'closer', 'messages.author', 'project.supervisor:id,name']);
    }

    public function reopen(User $actor, ProjectQuery $query): ProjectQuery
    {
        $this->assertCanReopen($actor, $query);

        $query->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        ActivityLogService::log(
            'reopen',
            'project_queries',
            "{$actor->name} reopened a query (\"{$query->subject}\") on \"{$query->project->title}\"",
            $actor->id,
            $query->project_id
        );

        return $query->fresh(['raiser', 'closer', 'messages.author', 'project.supervisor:id,name']);
    }

    public function listFor(User $actor, array $filters = []): LengthAwarePaginator
    {
        $studentProject = $actor->hasRole('student') ? $actor->studentProject() : null;

        $query = ProjectQuery::query()
            ->with(['raiser:id,name', 'project:id,title,student_id,program_id,supervisor_id', 'project.supervisor:id,name'])
            ->chronological();

        if ($studentProject) {
            $query->where('project_id', $studentProject->id);
        } elseif (FypProposal::canRespondProjectQueries($actor)) {
            $accessible = $this->programScope->accessibleProgramIds($actor);

            if ($accessible === []) {
                $query->whereRaw('1 = 0');
            } elseif ($accessible !== null) {
                $query->whereHas('project', fn ($p) => $p->whereIn('program_id', $accessible));
            }
        } else {
            $query->where('raised_by', $actor->id);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function canParticipate(User $user, ProjectQuery $query): bool
    {
        return $query->isVisibleTo($user);
    }

    public function assertCanCreate(User $actor, Project $project, string $role): void
    {
        $valid = match ($role) {
            'student' => $actor->studentProject()?->id === $project->id,
            'supervisor' => (int) $project->supervisor_id === (int) $actor->id,
            'evaluator' => ProjectEvaluator::query()
                ->where('project_id', $project->id)
                ->where('evaluator_id', $actor->id)
                ->exists(),
            'staff' => FypProposal::canRespondProjectQueries($actor)
                && $this->programScope->canAccessProject($actor, $project),
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                'project' => ['You are not authorized to raise a query for this project.'],
            ]);
        }
    }

    public function assertCanView(User $actor, ProjectQuery $query): void
    {
        if (! $query->isVisibleTo($actor)) {
            throw ValidationException::withMessages([
                'query' => ['You are not authorized to view this query.'],
            ]);
        }
    }

    public function assertCanReply(User $actor, ProjectQuery $query): void
    {
        $this->assertCanView($actor, $query);

        if ($query->status === 'closed') {
            throw ValidationException::withMessages([
                'status' => ['This query is closed. Ask an admin or committee head to reopen it before replying.'],
            ]);
        }
    }

    public function assertCanClose(User $actor, ProjectQuery $query): void
    {
        $this->assertCanView($actor, $query);

        if ($query->status === 'closed') {
            throw ValidationException::withMessages([
                'status' => ['This query is already closed.'],
            ]);
        }
    }

    public function assertCanReopen(User $actor, ProjectQuery $query): void
    {
        if (! FypProposal::canRespondProjectQueries($actor)) {
            throw ValidationException::withMessages([
                'query' => ['Only an admin, committee head, or user with permission can reopen a closed query.'],
            ]);
        }

        $this->assertCanView($actor, $query);

        if ($query->status !== 'closed') {
            throw ValidationException::withMessages([
                'status' => ['Only a closed query can be reopened.'],
            ]);
        }
    }

    public function assertCanDownload(User $actor, ProjectQuery $query): void
    {
        $this->assertCanView($actor, $query);
    }

    protected function resolveRaiserContext(User $actor, ?int $requestedProjectId): array
    {
        if ($actor->hasRole('student')) {
            $project = $actor->studentProject();

            if (! $project) {
                throw ValidationException::withMessages([
                    'project' => ['You are not part of an active project team.'],
                ]);
            }

            return [$project, 'student'];
        }

        if (! $requestedProjectId) {
            throw ValidationException::withMessages([
                'project_id' => ['Select which project this query concerns.'],
            ]);
        }

        try {
            $project = Project::findOrFail($requestedProjectId);
        } catch (ModelNotFoundException $e) {
            throw ValidationException::withMessages([
                'project_id' => ['Selected project could not be found.'],
            ]);
        }

        if ((int) $project->supervisor_id === (int) $actor->id) {
            return [$project, 'supervisor'];
        }

        if (ProjectEvaluator::query()->where('project_id', $project->id)->where('evaluator_id', $actor->id)->exists()) {
            return [$project, 'evaluator'];
        }

        if (FypProposal::canRespondProjectQueries($actor) && $this->programScope->canAccessProject($actor, $project)) {
            return [$project, 'staff'];
        }

        throw ValidationException::withMessages([
            'project_id' => ['You are not attached to this project as a supervisor or evaluator.'],
        ]);
    }

    protected function resolveActorRoleLabel(User $actor, ProjectQuery $query): string
    {
        if ((int) $actor->id === (int) $query->raised_by) {
            return $query->raised_by_role === 'staff' ? $this->resolveStaffRoleLabel($actor) : $query->raised_by_role;
        }

        if (in_array($query->raised_by_role, ['student', 'staff'], true)) {
            $studentProject = $actor->studentProject();

            if ($studentProject && (int) $studentProject->id === (int) $query->project_id) {
                return 'student';
            }
        }

        return $this->resolveStaffRoleLabel($actor);
    }

    protected function resolveStaffRoleLabel(User $actor): string
    {
        if ($actor->hasRole('admin')) {
            return 'admin';
        }

        if ($actor->hasRole('fyp-committee-head')) {
            return 'fyp-committee-head';
        }

        if ($actor->hasRole('fyp-committee-member')) {
            return 'fyp-committee-member';
        }

        return 'responder';
    }

    protected function isRaiserSide(User $actor, ProjectQuery $query): bool
    {
        if ((int) $actor->id === (int) $query->raised_by) {
            return true;
        }

        if ($query->raised_by_role === 'student') {
            $studentProject = $actor->studentProject();

            return $studentProject && (int) $studentProject->id === (int) $query->project_id;
        }

        return false;
    }

    protected function applyStatusTransitionOnReply(ProjectQuery $query, User $actor): void
    {
        if ($this->isRaiserSide($actor, $query)) {
            $query->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

            return;
        }

        $query->update(['status' => 'answered', 'closed_at' => null, 'closed_by' => null]);
    }

    protected function notifyOnCreate(ProjectQuery $query): void
    {
        $project = $query->project;

        if (! $project) {
            return;
        }

        if ($query->raised_by_role === 'staff') {
            $recipientIds = $project->members()
                ->where('status', 'active')
                ->pluck('user_id')
                ->push($project->student_id)
                ->filter()
                ->reject(fn ($id) => (int) $id === (int) $query->raised_by)
                ->unique()
                ->values()
                ->all();

            if (empty($recipientIds)) {
                return;
            }

            NotificationService::sendToMany(
                $recipientIds,
                'New Query From FYP Office',
                "The FYP office raised a query on \"{$project->title}\": {$query->subject}",
                'info',
                ['project_id' => $project->id, 'project_query_id' => $query->id, 'type' => 'project_query_created']
            );

            return;
        }

        if (! $project->program_id) {
            return;
        }

        $title = 'New Project Query';
        $message = "{$query->raiser?->name} raised a query on \"{$project->title}\": {$query->subject}";

        User::query()
            ->whereIn('id', $this->programScope->committeeMemberIds((int) $project->program_id))
            ->each(fn (User $user) => NotificationService::send(
                $user,
                $title,
                $message,
                'info',
                ['project_id' => $project->id, 'project_query_id' => $query->id, 'type' => 'project_query_created']
            ));
    }

    protected function notifyOnReply(ProjectQuery $query, ProjectQueryMessage $message, User $actor): void
    {
        $project = $query->project;

        if (! $project) {
            return;
        }

        if ($this->isRaiserSide($actor, $query)) {
            if ($query->raised_by_role === 'staff') {
                $recipientIds = $project->members()
                    ->where('status', 'active')
                    ->pluck('user_id')
                    ->push($project->student_id)
                    ->filter()
                    ->reject(fn ($id) => (int) $id === (int) $actor->id)
                    ->unique()
                    ->values()
                    ->all();

                if (empty($recipientIds)) {
                    return;
                }

                NotificationService::sendToMany(
                    $recipientIds,
                    'Query Follow-up From FYP Office',
                    "The FYP office added a follow-up on \"{$project->title}\" query: {$query->subject}",
                    'info',
                    ['project_id' => $project->id, 'project_query_id' => $query->id, 'type' => 'project_query_reply']
                );

                return;
            }

            if (! $project->program_id) {
                return;
            }

            User::query()
                ->whereIn('id', $this->programScope->committeeMemberIds((int) $project->program_id))
                ->each(fn (User $user) => NotificationService::send(
                    $user,
                    'Query Follow-up',
                    "New reply on \"{$project->title}\" query: {$query->subject}",
                    'info',
                    ['project_id' => $project->id, 'project_query_id' => $query->id, 'type' => 'project_query_reply']
                ));

            return;
        }

        $recipientIds = collect([$query->raised_by]);

        if ($query->raised_by_role === 'student') {
            $recipientIds = $recipientIds
                ->merge($project->members()->where('status', 'active')->pluck('user_id'))
                ->push($project->student_id);
        }

        $recipientIds = $recipientIds
            ->filter()
            ->unique()
            ->reject(fn ($id) => (int) $id === (int) $actor->id)
            ->values()
            ->all();

        if (empty($recipientIds)) {
            return;
        }

        NotificationService::sendToMany(
            $recipientIds,
            'Query Answered',
            "Your query \"{$query->subject}\" on \"{$project->title}\" received a reply.",
            'success',
            ['project_id' => $project->id, 'project_query_id' => $query->id, 'type' => 'project_query_reply']
        );
    }

    public function fileName(ProjectQuery $query): string
    {
        return "query-{$query->id}-thread.pdf";
    }

    public function generatePdf(ProjectQuery $query): string
    {
        $query->loadMissing([
            'project:id,title,program_id',
            'project.program:id,name',
            'raiser:id,name',
            'closer:id,name',
            'messages.author:id,name',
        ]);

        $html = view('queries.query-thread', $this->buildPdfViewData($query))->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    protected function buildPdfViewData(ProjectQuery $query): array
    {
        return [
            'subject' => $query->subject,
            'status' => ucfirst($query->status),
            'projectTitle' => $query->project?->title ?? '—',
            'programName' => $query->project?->program?->name ?? '—',
            'raiserName' => $query->raiser?->name ?? 'Unknown',
            'raiserRole' => ucwords(str_replace('-', ' ', $query->raised_by_role)),
            'createdAt' => $query->created_at?->format('d F Y H:i'),
            'closedAt' => $query->closed_at?->format('d F Y H:i'),
            'closedBy' => $query->closer?->name,
            'messages' => $query->messages->map(fn (ProjectQueryMessage $message) => [
                'author' => $message->author?->name ?? 'Unknown',
                'role' => ucwords(str_replace('-', ' ', $message->author_role)),
                'timestamp' => $message->created_at?->format('d M Y H:i'),
                'text' => $message->message,
            ])->all(),
            'generatedAt' => now()->format('d F Y H:i'),
        ];
    }
}
