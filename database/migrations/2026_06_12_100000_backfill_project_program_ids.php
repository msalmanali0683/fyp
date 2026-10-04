<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Project::query()
            ->whereNull('program_id')
            ->with('student:id,program_id')
            ->each(function (Project $project) {
                if ($project->student?->program_id) {
                    $project->update(['program_id' => $project->student->program_id]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill only.
    }
};
