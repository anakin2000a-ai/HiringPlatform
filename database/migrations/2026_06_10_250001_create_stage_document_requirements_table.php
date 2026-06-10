<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_document_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('due_days_after_stage_entry')->nullable();
            $table->timestamps();

            $table->unique(['workflow_stage_id', 'document_template_id'], 'sdr_stage_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_document_requirements');
    }
};
