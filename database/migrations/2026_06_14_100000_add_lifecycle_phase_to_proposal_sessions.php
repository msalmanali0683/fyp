<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_sessions', function (Blueprint $table) {
            $table->string('lifecycle_phase', 30)
                ->default('proposal_phase')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_sessions', function (Blueprint $table) {
            $table->dropColumn('lifecycle_phase');
        });
    }
};
