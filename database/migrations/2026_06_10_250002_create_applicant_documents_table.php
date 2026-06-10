<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->constrained('workflow_stages');
            $table->foreignId('stage_document_requirement_id')->nullable()->constrained('stage_document_requirements')->nullOnDelete();
            $table->foreignId('document_template_id')->constrained('document_templates');
            $table->string('status', 50)->default('pending');
            $table->string('file_path')->nullable();
            $table->string('external_signature_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_documents');
    }
};
