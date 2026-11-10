<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The audit viewer: filter by date range, and by what was done within a date range. Adding an index does not touch the append-only rows. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index('created_at', 'audit_events_created_at_index');                        // the list and the export, newest first, inside a date range
            $table->index(['action', 'created_at'], 'audit_events_action_created_at_index');     // "everything of this kind" inside a date range
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_events_action_created_at_index');
            $table->dropIndex('audit_events_created_at_index');
        });
    }
};
