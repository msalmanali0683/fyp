<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_invitations', function (Blueprint $table) {
            $table->text('response_comments')->nullable()->after('responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_invitations', function (Blueprint $table) {
            $table->dropColumn('response_comments');
        });
    }
};
