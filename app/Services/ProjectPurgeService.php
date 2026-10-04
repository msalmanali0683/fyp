<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectPurgeService
{
    public function purgeProjectPermanently(Project $project, ?User $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($project, $actor, $reason) {
            $project->invitations()->where('status', 'pending')->update([
                'status' => 'rejected',
                'responded_at' => now(),
                'response_comments' => $reason ?: 'Proposal session finalized.',
            ]);

            ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->update(['status' => 'removed']);

            Storage::disk(config('fyp.proposal.document_disk', 'public'))
                ->deleteDirectory('proposals/'.$project->id);

            $title = $project->title;
            $project->forceDelete();

            ActivityLogService::log(
                'delete',
                'projects',
                $reason
                    ? "Permanently removed pending proposal \"{$title}\": {$reason}"
                    : "Permanently removed pending proposal \"{$title}\"",
                $actor?->id
            );
        });
    }
}
