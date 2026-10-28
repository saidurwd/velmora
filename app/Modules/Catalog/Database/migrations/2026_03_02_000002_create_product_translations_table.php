<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 200);
            $table->string('slug', 190);
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();        // sanitised HTML
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps(6);

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['product_id', 'locale']);
            $table->unique(['locale', 'slug']);                  // PDP lookup by slug
            $table->fullText(['name', 'short_description']);     // search groundwork (finalised in Phase 4)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_translations');
    }
};
