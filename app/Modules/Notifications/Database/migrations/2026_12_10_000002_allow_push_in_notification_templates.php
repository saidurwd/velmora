<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 7: the app's push messages are a third channel. Only the list of allowed channels changes (notification_templates). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT notification_templates_check');
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_check CHECK (channel IN ('email','sms','push') AND kind IN ('transactional','marketing'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT notification_templates_check');
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_check CHECK (channel IN ('email','sms') AND kind IN ('transactional','marketing'))");
    }
};
