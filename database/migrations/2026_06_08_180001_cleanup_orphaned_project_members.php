<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'deleted_at')) {
            return;
        }

        DB::table('project_members')
            ->join('projects', 'projects.id', '=', 'project_members.project_id')
            ->where('project_members.status', 'active')
            ->whereNotNull('projects.deleted_at')
            ->update([
                'project_members.status' => 'removed',
                'project_members.updated_at' => now(),
            ]);

        DB::table('project_invitations')
            ->join('projects', 'projects.id', '=', 'project_invitations.project_id')
            ->where('project_invitations.status', 'pending')
            ->whereNotNull('projects.deleted_at')
            ->update([
                'project_invitations.status' => 'rejected',
                'project_invitations.responded_at' => now(),
                'project_invitations.response_comments' => 'Project was deleted.',
                'project_invitations.updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Orphan cleanup is not reversible.
    }
};
