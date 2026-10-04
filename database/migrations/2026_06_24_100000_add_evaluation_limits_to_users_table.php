<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('evaluation_limit_proposal')->nullable()->after('supervision_limit_phase_2');
            $table->unsignedSmallInteger('evaluation_limit_phase_1')->nullable()->after('evaluation_limit_proposal');
            $table->unsignedSmallInteger('evaluation_limit_phase_2')->nullable()->after('evaluation_limit_phase_1');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'evaluation_limit_proposal',
                'evaluation_limit_phase_1',
                'evaluation_limit_phase_2',
            ]);
        });
    }
};
