<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('repeat_decision')->nullable()->after('evaluator_resubmit_mode');
            $table->string('repeat_decision_phase')->nullable()->after('repeat_decision');
            $table->text('repeat_notes')->nullable()->after('repeat_decision_phase');
            $table->foreignId('repeat_decided_by')->nullable()->after('repeat_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('repeat_decided_at')->nullable()->after('repeat_decided_by');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repeat_decided_by');
            $table->dropColumn(['repeat_decision', 'repeat_decision_phase', 'repeat_notes', 'repeat_decided_at']);
        });
    }
};
