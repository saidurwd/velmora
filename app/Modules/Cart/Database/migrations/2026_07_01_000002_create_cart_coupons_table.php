<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The codes a shopper has typed into the cart. Only the code is kept; whether it still works is decided afresh each time the cart is shown.
        Schema::create('cart_coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->string('code', 64);                       // normalised (upper case)
            $table->timestamps(6);

            $table->unique(['cart_id', 'code']);               // reading a cart's codes; typing the same code twice is one row
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_coupons');
    }
};
