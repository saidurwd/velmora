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
        // Scheduled sale prices. [starts_at, ends_at) in UTC; two sales for the same variant and list can never overlap.
        Schema::create('price_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('price_list_id')->constrained('price_lists')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('BDT');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);

            $table->index(['variant_id', 'price_list_id', 'starts_at']); // current-sale lookup
        });

        DB::statement('ALTER TABLE price_sales ADD CONSTRAINT price_sales_amount_check CHECK (amount_minor >= 0)');
        DB::statement('ALTER TABLE price_sales ADD CONSTRAINT price_sales_window_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE price_sales ADD PERIOD FOR sale_window(starts_at, ends_at)');
        DB::statement('ALTER TABLE price_sales ADD UNIQUE KEY price_sales_no_overlap (variant_id, price_list_id, sale_window WITHOUT OVERLAPS)');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_sales');
    }
};
