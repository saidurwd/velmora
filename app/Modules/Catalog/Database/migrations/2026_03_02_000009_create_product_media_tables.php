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
        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete(); // image specific to one variant (e.g. a colour)
            $table->string('disk', 20);
            $table->string('base_path', 255);                // folder holding every rendition
            $table->unsignedSmallInteger('width');            // the stored master
            $table->unsignedSmallInteger('height');
            $table->json('renditions');                       // {"webp": {"320": "path", ...}, "avif": {...}}
            $table->unsignedInteger('size_bytes');            // master size
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_decorative')->default(false); // true = empty alt text is allowed
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);

            $table->index(['product_id', 'sort_order']);
            $table->index('variant_id');
        });

        // one live primary image per product, guaranteed by the database
        DB::statement('ALTER TABLE product_media ADD COLUMN primary_marker BIGINT UNSIGNED AS (IF(is_primary = 1 AND deleted_at IS NULL, product_id, NULL)) PERSISTENT');
        DB::statement('ALTER TABLE product_media ADD UNIQUE KEY product_media_one_primary (primary_marker)');

        Schema::create('product_media_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_id')->constrained('product_media')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('alt', 255);
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['media_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media_translations');
        Schema::dropIfExists('product_media');
    }
};
