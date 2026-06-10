<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('questionnaire_template_id')->constrained('questionnaire_templates')->cascadeOnDelete();
            $table->foreignId('questionnaire_question_id')->constrained('questionnaire_questions')->cascadeOnDelete();
            $table->json('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'questionnaire_question_id'], 'aa_app_question_unique');
            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_answers');
    }
};
