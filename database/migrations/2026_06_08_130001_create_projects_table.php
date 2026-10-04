<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('academic_year')->nullable();
            $table->enum('current_phase', ['proposal', 'phase_1', 'phase_2'])->default('proposal');
            $table->enum('status', ['active', 'completed', 'suspended'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
