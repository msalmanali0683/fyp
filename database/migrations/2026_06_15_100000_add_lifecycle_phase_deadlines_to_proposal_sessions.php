<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_sessions', function (Blueprint $table) {
            $table->dateTime('phase_1_initial_deadline')->nullable()->after('final_lock_deadline');
            $table->dateTime('phase_1_final_lock_deadline')->nullable()->after('phase_1_initial_deadline');
            $table->dateTime('phase_1_completed_at')->nullable()->after('phase_1_final_lock_deadline');
            $table->dateTime('phase_2_initial_deadline')->nullable()->after('phase_1_completed_at');
            $table->dateTime('phase_2_final_lock_deadline')->nullable()->after('phase_2_initial_deadline');
            $table->dateTime('phase_2_completed_at')->nullable()->after('phase_2_final_lock_deadline');
        });

        Schema::table('proposal_workflow_logs', function (Blueprint $table) {
            $table->string('fyp_phase', 30)->default('proposal')->after('project_id');
            $table->index(['project_id', 'fyp_phase']);
        });
    }

    public function down(): void
    {
        Schema::table('proposal_workflow_logs', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'fyp_phase']);
            $table->dropColumn('fyp_phase');
        });

        Schema::table('proposal_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'phase_1_initial_deadline',
                'phase_1_final_lock_deadline',
                'phase_1_completed_at',
                'phase_2_initial_deadline',
                'phase_2_final_lock_deadline',
                'phase_2_completed_at',
            ]);
        });
    }
};
