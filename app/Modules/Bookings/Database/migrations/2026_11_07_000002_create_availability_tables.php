<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a resource works in a normal week. Times are Dhaka wall-clock time. Weekday: 1 = Monday ... 7 = Sunday.
        Schema::create('availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('service_resources')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->date('valid_from')->nullable();                        // a rule may start or stop on a date (a seasonal timetable)
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'weekday']);                     // the windows of one resource on one weekday: what slot search reads
        });
        DB::statement('ALTER TABLE availability_rules ADD CONSTRAINT availability_rules_check CHECK (weekday BETWEEN 1 AND 7 AND end_time > start_time AND (valid_from IS NULL OR valid_to IS NULL OR valid_from <= valid_to))');

        // One day that is not like the week: closed, or different hours.
        Schema::create('availability_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('service_resources')->cascadeOnDelete();
            $table->date('on_date');
            $table->boolean('is_closed')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('note', 190)->nullable();
            $table->timestamps();

            $table->unique(['resource_id', 'on_date']);                    // one exception per resource and day
        });
        DB::statement('ALTER TABLE availability_exceptions ADD CONSTRAINT availability_exceptions_check CHECK ((is_closed = 1 AND start_time IS NULL AND end_time IS NULL) OR (is_closed = 0 AND start_time IS NOT NULL AND end_time IS NOT NULL AND end_time > start_time))');

        // A day the whole shop is closed (Eid, a national holiday): no resource takes bookings.
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('on_date')->unique();                             // one holiday per day; also finds a day
            $table->string('name', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['holidays', 'availability_exceptions', 'availability_rules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
