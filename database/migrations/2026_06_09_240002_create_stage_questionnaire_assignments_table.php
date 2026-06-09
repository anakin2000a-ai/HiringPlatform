<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_questionnaire_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->foreignId('questionnaire_template_id')->constrained('questionnaire_templates')->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['workflow_stage_id', 'questionnaire_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_questionnaire_assignments');
    }
};
