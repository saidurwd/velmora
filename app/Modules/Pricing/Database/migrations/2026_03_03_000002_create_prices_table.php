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
        // The regular (list) price of a variant in a price list. Money is integer poisha (BD-20).
        Schema::create('prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('price_list_id')->constrained('price_lists')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->bigInteger('compare_at_minor')->nullable(); // "was" price shown struck through
            $table->char('currency', 3)->default('BDT');
            $table->timestamps(6);

            $table->unique(['variant_id', 'price_list_id']);
        });

        DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_amount_check CHECK (amount_minor >= 0)');
        DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_compare_check CHECK (compare_at_minor IS NULL OR compare_at_minor > amount_minor)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
