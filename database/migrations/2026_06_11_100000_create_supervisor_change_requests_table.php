<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supervisor_change_requests')) {
            return;
        }

        Schema::create('supervisor_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('current_supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');

            $table->string('current_supervisor_status')->default('pending');
            $table->text('current_supervisor_comments')->nullable();
            $table->unsignedBigInteger('current_supervisor_responded_by')->nullable();
            $table->foreign('current_supervisor_responded_by', 'scr_current_responder_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->string('new_supervisor_status')->default('pending');
            $table->text('new_supervisor_comments')->nullable();
            $table->unsignedBigInteger('new_supervisor_responded_by')->nullable();
            $table->foreign('new_supervisor_responded_by', 'scr_new_responder_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->string('authority_status')->default('pending');
            $table->text('authority_comments')->nullable();
            $table->unsignedBigInteger('authority_decided_by')->nullable();
            $table->foreign('authority_decided_by', 'scr_authority_decider_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->string('overall_status')->default('pending');
            $table->timestamps();

            $table->index(['project_id', 'overall_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_change_requests');
    }
};
