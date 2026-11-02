<?php

declare(strict_types=1);

use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One customer's time with one resource. The slot is held while they pay, then confirmed. All times are UTC.
        Schema::create('service_bookings', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('package_id')->constrained('service_packages')->restrictOnDelete();
            $table->foreignId('resource_id')->constrained('service_resources')->restrictOnDelete();
            $table->unsignedBigInteger('order_item_id')->nullable();       // set when the booking is ordered (one booking per order line)
            $table->unsignedBigInteger('user_id')->nullable();             // the signed-in customer; NULL for a guest (the order says who)
            $table->string('status', 12)->default('held');
            $table->dateTime('starts_at', 6);                              // the service itself
            $table->dateTime('ends_at', 6);
            $table->dateTime('blocks_from', 6);                            // the service plus the buffer before it: what the resource is taken for
            $table->dateTime('blocks_until', 6);                           // the service plus the buffer after it
            $table->dateTime('hold_expires_at', 6)->nullable();            // while held: when the slot is given back if nobody pays
            $table->timestamps(6);

            $table->foreign('order_item_id')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique('order_item_id');                               // a line is one booking (several NULLs are allowed)
            $table->index(['resource_id', 'starts_at']);                   // a resource's day or week on the calendar
            $table->index(['status', 'hold_expires_at']);                  // finding holds that ran out
            $table->index(['user_id', 'starts_at']);                       // "My bookings"
        });

        DB::statement("ALTER TABLE service_bookings ADD CONSTRAINT service_bookings_check CHECK (status IN ('held','confirmed','in_progress','completed','cancelled','no_show') AND ends_at > starts_at AND blocks_from <= starts_at AND ends_at <= blocks_until AND (status <> 'held' OR hold_expires_at IS NOT NULL))");

        // THE guarantee against double booking: a resource cannot have two held or confirmed bookings whose blocked periods overlap, however many people
        // press "book" at the same instant. Cancelled, completed and no-show bookings give the time back. (MariaDB only; proved on 11.4 and 12.1.)
        DB::statement("ALTER TABLE service_bookings ADD COLUMN active_resource_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('held','confirmed'), resource_id, NULL)) PERSISTENT");
        DB::statement('ALTER TABLE service_bookings ADD PERIOD FOR slot(blocks_from, blocks_until)');
        DB::statement('ALTER TABLE service_bookings ADD UNIQUE INDEX service_bookings_no_overlap (active_resource_id, slot WITHOUT OVERLAPS)');

        StatusFlow::install('service_bookings', 'status', ['held' => ['confirmed', 'cancelled'], 'confirmed' => ['in_progress', 'completed', 'cancelled', 'no_show'], 'in_progress' => ['completed', 'no_show']]);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `service_bookings_status_flow`');
        Schema::dropIfExists('service_bookings');
    }
};
