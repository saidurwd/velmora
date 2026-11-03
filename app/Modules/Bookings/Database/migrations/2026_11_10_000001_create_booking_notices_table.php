<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "We told this customer this, this way, for this start time." The row is written BEFORE sending, so two workers or a retry can never send the same
        // message twice. The start time is part of the key: a booking that is moved is reminded again for its new time, and never twice for the same one.
        // No message text and no phone number is kept.
        Schema::create('booking_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('service_bookings')->restrictOnDelete();
            $table->string('kind', 20);                              // reminder_long | reminder_short | review
            $table->string('channel', 5);                            // sms | email
            $table->dateTime('for_starts_at', 6);
            $table->string('status', 10)->default('claimed');        // claimed | sent | failed | gave_up
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->string('last_error', 120)->nullable();           // the kind of failure, never a payload
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['booking_id', 'kind', 'channel', 'for_starts_at']);   // one message of each kind per channel and start time, even if two workers send at once
        });
        DB::statement("ALTER TABLE booking_notices ADD CONSTRAINT booking_notices_check CHECK (kind IN ('reminder_long','reminder_short','review') AND channel IN ('sms','email') AND status IN ('claimed','sent','failed','gave_up'))");
        AppendOnly::protect('booking_notices', ['status', 'attempts', 'last_error', 'updated_at']);   // only the outcome of sending may change
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `booking_notices_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `booking_notices_no_update`');
        Schema::dropIfExists('booking_notices');
    }
};
