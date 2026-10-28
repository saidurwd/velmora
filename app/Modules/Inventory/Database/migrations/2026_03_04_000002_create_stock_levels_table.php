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
        // Current counts per variant and location. The ledger (stock_movements) is the audit trail; these CHECKs are the last line of defence.
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->integer('on_hand')->default(0);   // physically in the location
            $table->integer('reserved')->default(0);  // promised to carts/orders, not yet shipped
            $table->integer('low_stock_threshold')->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->primary(['variant_id', 'location_id']);
            $table->index('location_id');
        });

        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_on_hand_check CHECK (on_hand >= 0)');
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_reserved_check CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_reserved_le_on_hand_check CHECK (reserved <= on_hand)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
