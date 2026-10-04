<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_phases', function (Blueprint $table) {
            $table->boolean('is_reevaluation')->default(false)->after('reviewed_by');
            $table->timestamp('reevaluation_deadline')->nullable()->after('is_reevaluation');
            $table->foreignId('reevaluated_by')->nullable()->after('reevaluation_deadline')->constrained('users')->nullOnDelete();
            $table->timestamp('reevaluated_at')->nullable()->after('reevaluated_by');
        });
    }

    public function down(): void
    {
        Schema::table('project_phases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reevaluated_by');
            $table->dropColumn(['is_reevaluation', 'reevaluation_deadline', 'reevaluated_at']);
        });
    }
};
