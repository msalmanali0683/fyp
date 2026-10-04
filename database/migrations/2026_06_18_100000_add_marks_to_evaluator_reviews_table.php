<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluator_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('evaluator_reviews', 'marks')) {
                $table->unsignedTinyInteger('marks')->nullable()->after('decision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('evaluator_reviews', function (Blueprint $table) {
            if (Schema::hasColumn('evaluator_reviews', 'marks')) {
                $table->dropColumn('marks');
            }
        });
    }
};
