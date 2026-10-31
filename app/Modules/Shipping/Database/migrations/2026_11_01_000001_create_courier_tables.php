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
        // One row per courier the shop uses. Several can be active; the manual courier is always there. Credentials are encrypted by the
        // application (never stored or logged in plain text) and are never shown again after they are saved.
        Schema::create('courier_accounts', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('courier', 30);                             // adapter name: manual, steadfast, pathao ...
            $table->string('label', 80);
            $table->boolean('is_active')->default(true);
            $table->text('credentials')->nullable();                   // encrypted
            $table->json('settings')->nullable();                      // non-secret options (pickup point, service type ...)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);

            $table->unique(['courier', 'label']);                      // the same account entered twice is refused
            $table->index(['is_active', 'sort_order']);                // the courier choice when creating a shipment
        });

        DB::statement("ALTER TABLE courier_accounts ADD CONSTRAINT courier_accounts_courier_check CHECK (courier REGEXP '^[a-z][a-z0-9_]*$')");

        // How a courier's own status words become ours. An unmapped word becomes "unknown" and raises an alert; it never breaks anything.
        Schema::create('courier_status_map', function (Blueprint $table): void {
            $table->id();
            $table->string('courier', 30);
            $table->string('provider_status', 80);
            $table->string('internal_status', 20);
            $table->string('note', 255)->nullable();
            $table->timestamps(6);

            $table->unique(['courier', 'provider_status']);            // one meaning per word; also serves the lookup when an event arrives
        });

        DB::statement("ALTER TABLE courier_status_map ADD CONSTRAINT courier_status_map_check CHECK (internal_status IN ('handed_over','in_transit','out_for_delivery','delivered','failed','returned'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_status_map');
        Schema::dropIfExists('courier_accounts');
    }
};
