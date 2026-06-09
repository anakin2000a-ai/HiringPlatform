<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name', 150);
            $table->string('last_name', 150);
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('source', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicants');
    }
};
