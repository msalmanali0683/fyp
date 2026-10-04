<?php

namespace Database\Seeders;

use App\Models\EvaluatorReview;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\ProposalWorkflowLog;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $leader = User::where('email', 'student@fyp.com')->first();
        $member2 = User::where('email', 'student2@fyp.com')->first();
        $member3 = User::where('email', 'student3@fyp.com')->first();
        $supervisor = User::where('email', 'supervisor@fyp.com')->first();

        if ($leader && $member2 && $member3 && $supervisor) {
            $project = Project::firstOrCreate(
                ['student_id' => $leader->id],
                [
                    'program_id' => $leader->program_id,
                    'supervisor_id' => $supervisor->id,
                    'supervisor_status' => 'pending',
                    'title' => 'A Framework for Detection and Analysis of Synthetic and Manipulated Image Content',
                    'description' => 'Research on synthetic image detection using deep learning techniques.',
                    'area_of_specialization' => 'Image Processing',
                    'academic_year' => '2025-2026',
                    'current_phase' => 'proposal',
                    'workflow_stage' => 'supervisor_pending',
                    'proposal_submitted_at' => now()->subDays(10),
                    'status' => 'active',
                ]
            );

            foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
                ProjectPhase::updateOrCreate(
                    ['project_id' => $project->id, 'phase' => $phase],
                    [
                        'content' => $phase === 'proposal'
                            ? 'Proposal document covering objectives, methodology, and expected outcomes.'
                            : null,
                        'status' => 'draft',
                    ]
                );
            }

            ProjectMember::firstOrCreate(
                ['project_id' => $project->id, 'user_id' => $leader->id],
                ['role' => 'leader']
            );

            foreach ([$member2, $member3] as $member) {
                ProjectMember::firstOrCreate(
                    ['project_id' => $project->id, 'user_id' => $member->id],
                    ['role' => 'member']
                );

                ProjectInvitation::firstOrCreate(
                    ['project_id' => $project->id, 'invitee_id' => $member->id],
                    [
                        'inviter_id' => $leader->id,
                        'status' => 'accepted',
                        'responded_at' => now()->subDays(8),
                    ]
                );
            }

            ProposalWorkflowLog::firstOrCreate(
                ['project_id' => $project->id, 'action' => 'Proposal submitted and invitations sent'],
                ['stage' => 'proposal_submitted', 'actor_id' => $leader->id, 'actor_role' => 'student']
            );

            ProposalWorkflowLog::firstOrCreate(
                ['project_id' => $project->id, 'action' => 'All group members confirmed'],
                ['stage' => 'group_confirmed', 'actor_id' => null, 'actor_role' => 'system']
            );

            Notification::firstOrCreate(
                [
                    'user_id' => $supervisor->id,
                    'title' => 'Supervision Request',
                ],
                [
                    'message' => "Group proposal \"{$project->title}\" is awaiting your supervision decision.",
                    'type' => 'info',
                    'meta' => ['project_id' => $project->id, 'type' => 'supervisor_request'],
                    'is_read' => false,
                ]
            );
        }

        $revisionLeader = User::where('email', 'student4@fyp.com')->first();
        $evaluator1 = User::where('email', 'evaluator@fyp.com')->first();
        $evaluator2 = User::where('email', 'evaluator2@fyp.com')->first();

        if ($revisionLeader && $supervisor && $evaluator1 && $evaluator2) {
            $revisionLeader->update(['is_proposal_enrolled' => true]);

            $revisionProject = Project::firstOrCreate(
                ['student_id' => $revisionLeader->id],
                [
                    'program_id' => $revisionLeader->program_id,
                    'supervisor_id' => $supervisor->id,
                    'supervisor_status' => 'accepted',
                    'title' => 'Smart Campus Navigation Using Indoor Positioning',
                    'description' => 'Mobile application for indoor navigation on university campuses.',
                    'area_of_specialization' => 'Mobile Computing',
                    'academic_year' => '2025-2026',
                    'current_phase' => 'proposal',
                    'workflow_stage' => 'revision_required',
                    'evaluator_resubmit_mode' => 'negative_only',
                    'proposal_submitted_at' => now()->subDays(20),
                    'status' => 'active',
                ]
            );

            foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
                ProjectPhase::updateOrCreate(
                    ['project_id' => $revisionProject->id, 'phase' => $phase],
                    [
                        'content' => $phase === 'proposal'
                            ? 'Initial proposal awaiting revision after evaluator feedback.'
                            : null,
                        'status' => 'draft',
                    ]
                );
            }

            ProjectMember::firstOrCreate(
                ['project_id' => $revisionProject->id, 'user_id' => $revisionLeader->id],
                ['role' => 'leader']
            );

            foreach ([$evaluator1, $evaluator2] as $evaluator) {
                ProjectEvaluator::firstOrCreate(
                    ['project_id' => $revisionProject->id, 'evaluator_id' => $evaluator->id]
                );

                EvaluatorReview::updateOrCreate(
                    ['project_id' => $revisionProject->id, 'evaluator_id' => $evaluator->id],
                    [
                        'decision' => $evaluator->id === $evaluator1->id ? 'revision_required' : 'accepted',
                        'comments' => $evaluator->id === $evaluator1->id
                            ? 'Please expand the methodology section and clarify evaluation metrics.'
                            : 'Proposal meets the baseline requirements.',
                        'reviewed_at' => now()->subDays(3),
                    ]
                );
            }

            ProposalWorkflowLog::firstOrCreate(
                ['project_id' => $revisionProject->id, 'action' => 'Committee returned proposal for revision'],
                [
                    'stage' => 'committee_final',
                    'actor_id' => User::where('email', 'committee@fyp.com')->value('id'),
                    'actor_role' => 'fyp-committee-member',
                    'comments' => 'Please expand the methodology section and clarify evaluation metrics.',
                ]
            );

            Notification::firstOrCreate(
                [
                    'user_id' => $revisionLeader->id,
                    'title' => 'Revision Required',
                ],
                [
                    'message' => 'Your proposal requires revision. Upload a revised PDF from My Project.',
                    'type' => 'warning',
                    'meta' => ['project_id' => $revisionProject->id],
                    'is_read' => false,
                ]
            );
        }

        foreach (User::all() as $user) {
            Notification::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'title' => 'Welcome to FYP Portal',
                ],
                [
                    'message' => 'Your account is ready. Enrolled students can register proposals from My Project.',
                    'type' => 'info',
                    'is_read' => false,
                ]
            );
        }
    }
}
