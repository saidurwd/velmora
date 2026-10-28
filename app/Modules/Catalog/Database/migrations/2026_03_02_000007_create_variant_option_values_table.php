<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_option_values', function (Blueprint $table): void {
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('attribute_value_id');
            $table->unsignedBigInteger('attribute_id');
            $table->primary(['variant_id', 'attribute_value_id']);
            $table->unique(['variant_id', 'attribute_id']);   // a variant has exactly one value per option attribute

            // the value must really belong to that attribute
            $table->foreign(['attribute_value_id', 'attribute_id'])->references(['id', 'attribute_id'])->on('attribute_values')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_option_values');
    }
};
