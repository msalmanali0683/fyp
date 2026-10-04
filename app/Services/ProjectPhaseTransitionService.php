<?php

namespace App\Services;

use App\Models\EvaluatorReview;
use App\Models\Project;
use App\Models\ProposalSession;

class ProjectPhaseTransitionService
{
    public function prepareApprovedProjectsForPhase1(ProposalSession $session): int
    {
        $prepared = 0;

        Project::query()
            ->where('proposal_session_id', $session->id)
            ->where('workflow_stage', 'approved')
            ->each(function (Project $project) use (&$prepared) {
                $this->resetDeliverablePhase($project, 'phase_1');
                $project->update(['current_phase' => 'phase_1']);
                $prepared++;
            });

        return $prepared;
    }

    public function carryForwardRepeatingPhase1Projects(ProposalSession $newSession): int
    {
        $carried = 0;

        Project::query()
            ->where('program_id', $newSession->program_id)
            ->where('proposal_session_id', '!=', $newSession->id)
            ->where('current_phase', 'phase_1')
            ->where(fn ($query) => $query
                ->whereNull('repeat_decision')
                ->orWhere('repeat_decision', '!=', 'resubmission_required'))
            ->whereDoesntHave('phases', fn ($query) => $query
                ->where('phase', 'phase_1')
                ->where('status', 'approved'))
            ->each(function (Project $project) use ($newSession, &$carried) {
                $this->resetDeliverablePhase($project, 'phase_1');
                $project->update([
                    'proposal_session_id' => $newSession->id,
                    'repeat_decision' => null,
                    'repeat_decision_phase' => null,
                    'repeat_notes' => null,
                    'repeat_decided_by' => null,
                    'repeat_decided_at' => null,
                ]);
                $carried++;
            });

        return $carried;
    }

    /**
     * Mirrors carryForwardRepeatingPhase1Projects() for Phase 2 — swept in when a session
     * transitions into being the active Phase-2 session for the program (i.e. when that
     * session completes its own Phase 1), so stray Phase-2 projects from an older,
     * already-archived session get a home to continue in instead of being stuck forever.
     */
    public function carryForwardRepeatingPhase2Projects(ProposalSession $newSession): int
    {
        $carried = 0;

        Project::query()
            ->where('program_id', $newSession->program_id)
            ->where('proposal_session_id', '!=', $newSession->id)
            ->where('current_phase', 'phase_2')
            ->where(fn ($query) => $query
                ->whereNull('repeat_decision')
                ->orWhere('repeat_decision', '!=', 'resubmission_required'))
            ->whereDoesntHave('phases', fn ($query) => $query
                ->where('phase', 'phase_2')
                ->where('status', 'approved'))
            ->each(function (Project $project) use ($newSession, &$carried) {
                $this->resetDeliverablePhase($project, 'phase_2');
                $project->update([
                    'proposal_session_id' => $newSession->id,
                    'repeat_decision' => null,
                    'repeat_decision_phase' => null,
                    'repeat_notes' => null,
                    'repeat_decided_by' => null,
                    'repeat_decided_at' => null,
                ]);
                $carried++;
            });

        return $carried;
    }

    /**
     * Pending (never-approved) proposal-phase projects left behind when a session's
     * proposal phase finished are carried into the next proposal-phase session for the
     * same program, rather than being deleted — their team and draft continue unchanged.
     */
    public function carryForwardPendingProposalProjects(ProposalSession $newSession): int
    {
        $carried = 0;

        Project::query()
            ->where('program_id', $newSession->program_id)
            ->where('proposal_session_id', '!=', $newSession->id)
            ->where('current_phase', 'proposal')
            ->where('workflow_stage', '!=', 'approved')
            ->each(function (Project $project) use ($newSession, &$carried) {
                $project->update(['proposal_session_id' => $newSession->id]);
                $carried++;
            });

        return $carried;
    }

    public function advanceApprovedPhase1ProjectsToPhase2(ProposalSession $session): int
    {
        $advanced = 0;

        Project::query()
            ->where('proposal_session_id', $session->id)
            ->where('current_phase', 'phase_1')
            ->whereHas('phases', fn ($query) => $query
                ->where('phase', 'phase_1')
                ->where('status', 'approved'))
            ->each(function (Project $project) use (&$advanced) {
                $this->resetDeliverablePhase($project, 'phase_2');
                $project->update(['current_phase' => 'phase_2']);
                $advanced++;
            });

        return $advanced;
    }

    public function completeApprovedPhase2Projects(ProposalSession $session): int
    {
        $completed = 0;

        Project::query()
            ->where('proposal_session_id', $session->id)
            ->where('current_phase', 'phase_2')
            ->whereHas('phases', fn ($query) => $query
                ->where('phase', 'phase_2')
                ->where('status', 'approved'))
            ->each(function (Project $project) use (&$completed) {
                $project->update(['status' => 'completed']);
                $completed++;
            });

        return $completed;
    }

    public function countPendingPhase1Projects(ProposalSession $session): int
    {
        return Project::query()
            ->where('proposal_session_id', $session->id)
            ->where('current_phase', 'phase_1')
            ->whereDoesntHave('phases', fn ($query) => $query
                ->where('phase', 'phase_1')
                ->where('status', 'approved'))
            ->count();
    }

    public function countPendingPhase2Projects(ProposalSession $session): int
    {
        return Project::query()
            ->where('proposal_session_id', $session->id)
            ->where('current_phase', 'phase_2')
            ->whereDoesntHave('phases', fn ($query) => $query
                ->where('phase', 'phase_2')
                ->where('status', 'approved'))
            ->count();
    }

    public function hasDeliverableStarted(Project $project, string $phase): bool
    {
        $row = $project->phases()->where('phase', $phase)->first();

        if (! $row) {
            return false;
        }

        if (filled($row->attachment) || filled($row->content)) {
            return true;
        }

        return in_array($row->status, ['submitted', 'under_review', 'approved', 'revision_required'], true);
    }

    protected function resetDeliverablePhase(Project $project, string $phase): void
    {
        $project->phases()->updateOrCreate(
            ['phase' => $phase],
            [
                'content' => null,
                'attachment' => null,
                'status' => 'draft',
                'workflow_stage' => 'draft',
                'feedback' => null,
                'submitted_at' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]
        );

        EvaluatorReview::query()
            ->where('project_id', $project->id)
            ->where('fyp_phase', $phase)
            ->delete();
    }
}
