<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('subject', 250);
            $table->string('event_type', 150)->nullable();
            $table->json('payload');
            $table->string('status', 50)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_events');
    }
};
