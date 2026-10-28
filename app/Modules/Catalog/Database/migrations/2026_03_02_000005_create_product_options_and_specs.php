<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which option attributes a product varies by (e.g. Size, Colour), in display order.
        Schema::create('product_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->restrictOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->unique(['product_id', 'attribute_id']);
        });

        // Display/filter specifications (e.g. Material: Cotton).
        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->restrictOnDelete();
            $table->primary(['product_id', 'attribute_value_id']);
            $table->index('attribute_value_id'); // facet: products having a value
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_options');
    }
};
