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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('product_type', 16);                  // physical | digital | service: drives fulfilment rules (BD-40)
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->string('tax_class_code', 40)->default('standard'); // resolved by the Pricing module
            $table->string('status', 16)->default('draft');
            $table->dateTime('publish_at', 6)->nullable();       // scheduled publication
            $table->dateTime('published_at', 6)->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // staff user id (no FK: identity belongs to another module)
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);

            $table->index(['status', 'published_at']);   // storefront listings
            $table->index(['status', 'publish_at']);     // scheduled-publish job
            $table->index(['product_type', 'status']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (product_type IN ('physical','digital','service'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft','published','archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
