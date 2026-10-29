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
        // The address as it was when the order was placed. The customer's address book can change; this never does by itself.
        Schema::create('order_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('type', 10);                    // shipping | billing
            $table->string('recipient_name', 150);
            $table->string('phone', 20);                   // E.164
            $table->unsignedBigInteger('division_id')->nullable();   // reference only, no FK: geography may be corrected later
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('upazila_id')->nullable();
            $table->json('location_snapshot');             // {"division": {"en","bn"}, "district": {...}, "upazila": {...}}
            $table->string('postal_code', 4)->nullable();
            $table->string('area', 150)->nullable();
            $table->string('address_line', 255);
            $table->string('landmark', 150)->nullable();
            $table->timestamps(6);

            $table->unique(['order_id', 'type']);          // one shipping and at most one billing address per order
            $table->index('phone');                        // support search, COD velocity checks
        });

        DB::statement("ALTER TABLE order_addresses ADD CONSTRAINT order_addresses_type_check CHECK (type IN ('shipping','billing'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
