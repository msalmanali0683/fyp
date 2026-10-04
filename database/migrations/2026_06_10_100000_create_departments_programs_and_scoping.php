<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['department_id', 'code']);
        });

        Schema::create('program_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_committee_head')->default(false);
            $table->boolean('is_committee_member')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'program_id']);
        });

        Schema::create('program_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grantee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['program_id', 'grantee_user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('session')->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('student_id')->constrained()->nullOnDelete();
        });

        Schema::create('program_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            $table->timestamps();

            $table->unique(['program_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_settings');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::dropIfExists('program_access_grants');
        Schema::dropIfExists('program_memberships');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('departments');
    }
};
