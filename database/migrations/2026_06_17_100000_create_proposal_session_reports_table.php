<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_session_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_session_id')->constrained()->cascadeOnDelete();
            $table->string('lifecycle_phase', 30);
            $table->string('report_type', 40);
            $table->string('batch_key', 40);
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['proposal_session_id', 'lifecycle_phase'], 'psr_session_lifecycle_idx');
            $table->index(['proposal_session_id', 'batch_key'], 'psr_session_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_session_reports');
    }
};
