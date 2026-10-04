<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('records', 'deleted_at')) {
            DB::table('records')->whereNotNull('deleted_at')->delete();
            Schema::table('records', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('records', 'deleted_at')) {
            Schema::table('records', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }
};
