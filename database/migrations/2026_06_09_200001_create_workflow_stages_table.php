<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hiring_workflow_id')->constrained('hiring_workflows')->cascadeOnDelete();
            $table->string('name');
            $table->string('stage_type', 100);
            $table->unsignedInteger('position');
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('auto_advance_enabled')->default(false);
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['hiring_workflow_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
    }
};
