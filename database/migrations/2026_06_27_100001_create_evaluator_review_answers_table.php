<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluator_review_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluator_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('evaluation_questions')->restrictOnDelete();
            $table->unsignedSmallInteger('marks_awarded');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['evaluator_review_id', 'question_id'], 'evaluator_review_answers_unique');
        });

        Schema::table('evaluator_reviews', function (Blueprint $table) {
            $table->unsignedSmallInteger('marks')->nullable()->change();
            $table->unsignedSmallInteger('max_marks')->nullable()->after('marks');
        });
    }

    public function down(): void
    {
        Schema::table('evaluator_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('marks')->nullable()->change();
            $table->dropColumn('max_marks');
        });

        Schema::dropIfExists('evaluator_review_answers');
    }
};
