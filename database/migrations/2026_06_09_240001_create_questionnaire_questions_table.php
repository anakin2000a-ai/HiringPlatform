<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('questionnaire_template_id')->constrained('questionnaire_templates')->cascadeOnDelete();
            $table->string('question_key', 150);
            $table->text('label');
            $table->string('type', 50);
            $table->json('options')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('visibility_rules')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['questionnaire_template_id', 'question_key'], 'qq_template_question_key_unique');
            $table->index(['questionnaire_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_questions');
    }
};
