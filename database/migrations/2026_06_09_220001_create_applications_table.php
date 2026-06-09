<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->foreignId('job_opening_id')->constrained('job_openings')->cascadeOnDelete();
            $table->foreignId('current_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $table->string('status', 50)->default('active');
            $table->integer('score')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('hired_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'job_opening_id']);
            $table->index('current_stage_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
