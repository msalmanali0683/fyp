<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_query_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role');
            $table->text('message');
            $table->timestamps();

            $table->index('project_query_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_query_messages');
    }
};
