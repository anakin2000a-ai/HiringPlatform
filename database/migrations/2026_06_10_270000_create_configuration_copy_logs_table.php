<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_copy_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('target_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('copied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('copied_items');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_copy_logs');
    }
};
