<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name', 255);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 50)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_templates');
    }
};
