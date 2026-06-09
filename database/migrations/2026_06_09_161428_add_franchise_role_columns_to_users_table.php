<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('franchise_account_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
            $table->string('role', 100)->default('viewer')->after('password');
            $table->string('access_scope', 50)->default('store')->after('role');
            $table->string('status', 50)->default('active')->after('access_scope');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('franchise_account_id');
            $table->dropColumn(['role', 'access_scope', 'status']);
        });
    }
};
