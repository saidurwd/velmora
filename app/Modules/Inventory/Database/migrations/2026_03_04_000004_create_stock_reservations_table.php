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
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('owner_type', 12);          // cart | order
            $table->string('owner_ref', 64);           // public id of the cart/order
            $table->string('status', 12)->default('active');
            $table->dateTime('expires_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['status', 'expires_at']);       // expiry job
            $table->index(['owner_type', 'owner_ref']);    // release everything an order holds
            $table->index(['variant_id', 'location_id', 'status']); // reconcile reserved counts
        });

        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_qty_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_owner_check CHECK (owner_type IN ('cart','order'))");
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_check CHECK (status IN ('active','committed','released','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
