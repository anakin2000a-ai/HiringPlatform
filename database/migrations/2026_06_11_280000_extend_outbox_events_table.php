<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbox_events', 'available_at')) {
                $table->timestamp('available_at')->nullable()->after('failed_at');
            }

            if (! Schema::hasColumn('outbox_events', 'last_error')) {
                $table->text('last_error')->nullable()->after('available_at');
            }

            $table->index(['status', 'available_at'], 'outbox_events_status_available_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropIndex('outbox_events_status_available_at_idx');

            if (Schema::hasColumn('outbox_events', 'last_error')) {
                $table->dropColumn('last_error');
            }

            if (Schema::hasColumn('outbox_events', 'available_at')) {
                $table->dropColumn('available_at');
            }
        });
    }
};
