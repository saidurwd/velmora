<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 7: the app's push messages are a third channel. Only the list of allowed channels changes (notification_consent_events). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_consent_events DROP CONSTRAINT notification_consent_events_check');
        DB::statement("ALTER TABLE notification_consent_events ADD CONSTRAINT notification_consent_events_check CHECK (channel IN ('email','sms','push') AND source IN ('account','checkout','staff','link'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_consent_events DROP CONSTRAINT notification_consent_events_check');
        DB::statement("ALTER TABLE notification_consent_events ADD CONSTRAINT notification_consent_events_check CHECK (channel IN ('email','sms') AND source IN ('account','checkout','staff','link'))");
    }
};
