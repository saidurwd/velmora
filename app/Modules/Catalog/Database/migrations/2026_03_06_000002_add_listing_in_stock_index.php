<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_listing', function (Blueprint $table): void {
            $table->index(['in_stock', 'published_at'], 'catalog_listing_in_stock_published_idx'); // "in stock only", newest first
        });
    }

    public function down(): void
    {
        Schema::table('catalog_listing', function (Blueprint $table): void {
            $table->dropIndex('catalog_listing_in_stock_published_idx');
        });
    }
};
