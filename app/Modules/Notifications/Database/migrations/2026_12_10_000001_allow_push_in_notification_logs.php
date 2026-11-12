<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 7: the app's push messages are a third channel. Only the list of allowed channels changes (notification_logs). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_logs DROP CONSTRAINT notification_logs_check');
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_check CHECK (channel IN ('email','sms','push') AND kind IN ('transactional','marketing') AND status IN ('queued','sending','sent','failed','dead','skipped'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_logs DROP CONSTRAINT notification_logs_check');
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_check CHECK (channel IN ('email','sms') AND kind IN ('transactional','marketing') AND status IN ('queued','sending','sent','failed','dead','skipped'))");
    }
};
