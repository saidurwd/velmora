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
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->unique();      // a signed-in customer has one cart
            $table->char('guest_token_hash', 64)->nullable()->unique();       // sha256 of the cookie token; the token itself is never stored
            $table->char('currency', 3)->default('BDT');
            $table->dateTime('expires_at', 6);                                // refreshed on every change; swept by the purge job
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->timestamps(6);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('expires_at'); // purge: carts past their expiry
        });

        DB::statement('ALTER TABLE carts ADD CONSTRAINT carts_has_owner_check CHECK (user_id IS NOT NULL OR guest_token_hash IS NOT NULL)');

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->bigInteger('added_price_minor');   // unit price when the customer last saw/accepted it: drives "the price changed" notice
            $table->timestamps(6);

            $table->unique(['cart_id', 'variant_id']);  // one line per variant; adding again increases the quantity
            $table->index('variant_id');
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_price_check CHECK (added_price_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
