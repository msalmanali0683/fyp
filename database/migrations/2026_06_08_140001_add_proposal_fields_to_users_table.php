<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_no')->nullable()->after('phone');
            $table->string('father_name')->nullable()->after('registration_no');
            $table->string('program')->nullable()->after('father_name');
            $table->string('department')->nullable()->after('program');
            $table->string('session')->nullable()->after('department');
            $table->boolean('is_proposal_enrolled')->default(false)->after('session');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'registration_no',
                'father_name',
                'program',
                'department',
                'session',
                'is_proposal_enrolled',
            ]);
        });
    }
};
