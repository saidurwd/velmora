<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Read model for storefront browsing: one row per LIVE product, rebuilt by ListingProjection. It lets the
        // listing page filter and sort by price/stock/newest with plain indexes, without joining other modules' tables.
        Schema::create('catalog_listing', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->primary();
            $table->string('product_type', 12);
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('primary_category_id')->nullable();
            $table->bigInteger('min_price_minor');                     // lowest displayed variant price (sale applied)
            $table->bigInteger('max_price_minor');
            $table->bigInteger('compare_at_minor')->nullable();        // "was" price of the cheapest variant, when on sale
            $table->boolean('on_sale')->default(false);
            $table->boolean('in_stock')->default(true);
            $table->unsignedInteger('popularity')->default(0);         // interim: curated (bestseller tag) until real sales exist (Phase 5)
            $table->dateTime('published_at', 6);
            $table->string('image_disk', 20)->nullable();
            $table->json('image_renditions')->nullable();              // {"320": path, "640": path, …}
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->dateTime('refreshed_at', 6);

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->index(['published_at', 'product_id']);                // sort: newest
            $table->index(['min_price_minor', 'product_id']);             // sort/filter: price
            $table->index(['brand_id', 'published_at']);                  // brand page
            $table->index(['product_type', 'published_at']);              // type filter
            $table->index(['popularity', 'published_at']);                // sort: popular
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_listing');
    }
};
