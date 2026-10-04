<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->boolean('is_submission_open')->default(false);
            $table->dateTime('initial_draft_deadline')->nullable();
            $table->dateTime('final_lock_deadline')->nullable();
            $table->boolean('is_fully_locked')->default(false);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'code']);
        });

        Schema::create('proposal_session_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('extended_initial_deadline')->nullable();
            $table->dateTime('extended_final_deadline')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['proposal_session_id', 'user_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('proposal_session_id')
                ->nullable()
                ->after('program_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposal_session_id');
        });

        Schema::dropIfExists('proposal_session_extensions');
        Schema::dropIfExists('proposal_sessions');
    }
};
