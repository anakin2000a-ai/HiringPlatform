<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('hiring_workflow_id')->constrained('hiring_workflows')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('employment_type', 100)->nullable();
            $table->unsignedInteger('openings_count')->default(1);
            $table->string('status', 50)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index('hiring_workflow_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_openings');
    }
};
