<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A phone that may receive push messages (Phase 11, ADR P11-2). A device belongs to ONE person and ONE sign-in (`family_id` is the id shown to the
 * customer as the session): when that sign-in ends, the device is deleted, so nothing is pushed to a signed-out phone. The same push token
 * registered by someone else MOVES the row (the phone changed hands). The push token itself is kept encrypted; its SHA-256 is how it is found.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();   // DELETE /devices/{id}: find by the id the app was given
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('family_id', 26)->charset('ascii')->collation('ascii_bin');
            $table->string('platform', 10);
            $table->char('push_token_hash', 64)->charset('ascii')->collation('ascii_bin')->unique(); // POST /devices: find (and move) the row for this push token
            $table->text('push_token_encrypted');
            $table->string('app_version', 20)->nullable();
            $table->char('locale', 2)->default('en');
            $table->dateTime('last_seen_at', 6);
            $table->dateTime('created_at', 6);

            $table->index('user_id');                           // sending a push to a person; logout-all
            $table->index('family_id');                         // a sign-in ends: remove its device
        });

        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_check CHECK (platform IN ('android','ios'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
