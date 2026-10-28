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
        // An attribute is either a variant OPTION (size, colour) or a display/filter SPEC (material).
        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('kind', 8);                      // option | spec
            $table->boolean('is_filterable')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);
        });
        DB::statement("ALTER TABLE attributes ADD CONSTRAINT attributes_kind_check CHECK (kind IN ('option','spec'))");

        Schema::create('attribute_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 100);
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['attribute_id', 'locale']);
        });

        Schema::create('attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('swatch', 9)->nullable();         // optional colour hex for colour options
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);

            $table->unique(['attribute_id', 'code']);
            $table->unique(['id', 'attribute_id']);           // target of the composite FK on variant_option_values
        });

        Schema::create('attribute_value_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('label', 100);
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['attribute_value_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_value_translations');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attribute_translations');
        Schema::dropIfExists('attributes');
    }
};
