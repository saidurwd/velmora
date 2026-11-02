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
        // What happened to a booking, in order. Never changed or deleted.
        Schema::create('booking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('service_bookings')->restrictOnDelete();
            $table->string('event', 24);
            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['booking_id', 'id']);                           // the history of one booking, in order
        });
        AppendOnly::protect('booking_events');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `booking_events_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `booking_events_no_update`');
        Schema::dropIfExists('booking_events');
    }
};
