<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 7: a booking reminder may also be pushed to the app. Only the list of allowed channels changes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE booking_notices DROP CONSTRAINT booking_notices_check');
        DB::statement("ALTER TABLE booking_notices ADD CONSTRAINT booking_notices_check CHECK (kind IN ('reminder_long','reminder_short','review') AND channel IN ('sms','email','push') AND status IN ('claimed','sent','failed','gave_up'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE booking_notices DROP CONSTRAINT booking_notices_check');
        DB::statement("ALTER TABLE booking_notices ADD CONSTRAINT booking_notices_check CHECK (kind IN ('reminder_long','reminder_short','review') AND channel IN ('sms','email') AND status IN ('claimed','sent','failed','gave_up'))");
    }
};
