<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('supervision_limit_proposal')->nullable()->after('is_proposal_enrolled');
            $table->unsignedSmallInteger('supervision_limit_phase_1')->nullable()->after('supervision_limit_proposal');
            $table->unsignedSmallInteger('supervision_limit_phase_2')->nullable()->after('supervision_limit_phase_1');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'supervision_limit_proposal',
                'supervision_limit_phase_1',
                'supervision_limit_phase_2',
            ]);
        });
    }
};
