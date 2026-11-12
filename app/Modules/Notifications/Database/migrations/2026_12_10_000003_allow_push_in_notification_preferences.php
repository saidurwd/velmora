<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 7: the app's push messages are a third channel. Only the list of allowed channels changes (notification_preferences). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT notification_preferences_check');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_check CHECK (channel IN ('email','sms','push') AND ((quiet_from IS NULL) = (quiet_to IS NULL)))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT notification_preferences_check');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_check CHECK (channel IN ('email','sms') AND ((quiet_from IS NULL) = (quiet_to IS NULL)))");
    }
};
