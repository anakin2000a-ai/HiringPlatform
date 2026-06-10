<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stage_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hiring_workflow_id')->constrained('hiring_workflows')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->boolean('is_manual_allowed')->default(true);
            $table->boolean('is_automatic_allowed')->default(true);
            $table->json('conditions')->nullable();
            $table->timestamps();

            // Note: unique enforced at app layer due to NULL handling in MySQL unique indexes
            $table->index(['hiring_workflow_id', 'from_stage_id', 'to_stage_id'], 'wst_workflow_from_to_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stage_transitions');
    }
};
