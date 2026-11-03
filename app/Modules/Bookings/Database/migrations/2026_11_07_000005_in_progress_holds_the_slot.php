<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A booking that is under way still has the resource: nobody may be booked over it. (The first version of the guard only counted held and confirmed.)
        // No booking is on sale yet, so the index can be rebuilt freely.
        DB::statement('ALTER TABLE service_bookings DROP INDEX service_bookings_no_overlap');
        DB::statement("ALTER TABLE service_bookings MODIFY COLUMN active_resource_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('held','confirmed','in_progress'), resource_id, NULL)) PERSISTENT");
        DB::statement('ALTER TABLE service_bookings ADD UNIQUE INDEX service_bookings_no_overlap (active_resource_id, slot WITHOUT OVERLAPS)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_bookings DROP INDEX service_bookings_no_overlap');
        DB::statement("ALTER TABLE service_bookings MODIFY COLUMN active_resource_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('held','confirmed'), resource_id, NULL)) PERSISTENT");
        DB::statement('ALTER TABLE service_bookings ADD UNIQUE INDEX service_bookings_no_overlap (active_resource_id, slot WITHOUT OVERLAPS)');
    }
};
