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
        // The sellable unit (BD-40): cart, orders, stock and prices reference variant_id.
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('sku', 64)->unique();             // case-insensitive under the project collation
            $table->string('barcode', 64)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('track_inventory')->default(false);
            $table->string('option_signature', 190)->default(''); // sorted attribute_value ids, e.g. "12-45"; '' for a simple product
            $table->unsignedInteger('weight_g')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->unsignedInteger('min_qty')->default(1);
            $table->unsignedInteger('max_qty')->nullable();
            $table->unsignedInteger('qty_step')->default(1);
            $table->string('status', 12)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);

            $table->index('barcode');                          // scan lookup
            $table->index(['product_id', 'status', 'sort_order']);
        });

        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT variants_status_check CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT variants_qty_check CHECK (min_qty >= 1 AND qty_step >= 1 AND (max_qty IS NULL OR max_qty >= min_qty))');

        // At most ONE live default variant per product, and ONE live variant per option combination: guaranteed by unique keys on generated columns.
        DB::statement('ALTER TABLE product_variants ADD COLUMN default_marker BIGINT UNSIGNED AS (IF(is_default = 1 AND deleted_at IS NULL, product_id, NULL)) PERSISTENT');
        DB::statement('ALTER TABLE product_variants ADD COLUMN live_signature VARCHAR(190) AS (IF(deleted_at IS NULL, option_signature, NULL)) PERSISTENT');
        DB::statement('ALTER TABLE product_variants ADD UNIQUE KEY variants_one_default (default_marker)');
        DB::statement('ALTER TABLE product_variants ADD UNIQUE KEY variants_one_per_combination (product_id, live_signature)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
