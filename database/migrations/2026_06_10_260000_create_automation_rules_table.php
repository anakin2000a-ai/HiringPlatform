<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('hiring_workflow_id')->nullable()->constrained('hiring_workflows')->nullOnDelete();
            $table->foreignId('workflow_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $table->string('name', 255);
            $table->string('trigger', 100);
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'trigger', 'is_active'], 'ar_store_trigger_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
