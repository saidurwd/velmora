<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a signed-in customer has saved for later. A guest has no wishlist.
        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->dateTime('created_at', 6);

            $table->unique(['user_id', 'variant_id']);   // saving twice is one row; "is this saved?" and one customer's list (newest first by id)
            $table->index('variant_id');                 // the foreign key; how many customers want a variant
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
