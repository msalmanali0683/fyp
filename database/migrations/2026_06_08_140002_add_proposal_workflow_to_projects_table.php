<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('area_of_specialization')->nullable()->after('description');
            $table->enum('supervisor_status', ['pending', 'accepted', 'rejected'])->default('pending')->after('supervisor_id');
            $table->text('supervisor_rejection_feedback')->nullable()->after('supervisor_status');
            $table->string('workflow_stage')->default('draft')->after('current_phase');
            $table->timestamp('proposal_submitted_at')->nullable()->after('workflow_stage');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'area_of_specialization',
                'supervisor_status',
                'supervisor_rejection_feedback',
                'workflow_stage',
                'proposal_submitted_at',
            ]);
        });
    }
};
