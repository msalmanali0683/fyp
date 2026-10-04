<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_phases', function (Blueprint $table) {
            if (! Schema::hasColumn('project_phases', 'workflow_stage')) {
                $table->string('workflow_stage', 50)->default('draft')->after('status');
            }
        });

        Schema::table('evaluator_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('evaluator_reviews', 'fyp_phase')) {
                $table->string('fyp_phase', 30)->default('proposal')->after('evaluator_id');
            }
        });

        Schema::table('evaluator_reviews', function (Blueprint $table) {
            $table->unique(
                ['project_id', 'evaluator_id', 'fyp_phase'],
                'evaluator_reviews_project_evaluator_phase_unique'
            );
        });

        Schema::table('evaluator_reviews', function (Blueprint $table) {
            $table->dropUnique('evaluator_reviews_project_id_evaluator_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('evaluator_reviews', function (Blueprint $table) {
            $table->unique(['project_id', 'evaluator_id']);
            $table->dropUnique('evaluator_reviews_project_evaluator_phase_unique');
            $table->dropColumn('fyp_phase');
        });

        Schema::table('project_phases', function (Blueprint $table) {
            $table->dropColumn('workflow_stage');
        });
    }
};
