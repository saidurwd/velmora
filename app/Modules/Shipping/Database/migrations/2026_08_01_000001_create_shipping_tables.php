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
        Schema::create('shipping_zones', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->boolean('is_fallback')->default(false);         // the zone for every district no other zone claims
            $table->boolean('cod_available')->default(true);        // cash on delivery offered in this zone
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);
        });

        // Exactly one fallback zone, enforced by the database (NULL never collides).
        DB::statement('ALTER TABLE shipping_zones ADD COLUMN fallback_marker TINYINT GENERATED ALWAYS AS (IF(is_fallback, 1, NULL)) VIRTUAL');
        DB::statement('ALTER TABLE shipping_zones ADD UNIQUE INDEX shipping_zones_one_fallback (fallback_marker)');

        Schema::create('shipping_zone_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 120);
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['zone_id', 'locale']);
        });

        // A district belongs to at most one zone, so a delivery address always resolves to one answer.
        Schema::create('shipping_zone_districts', function (Blueprint $table): void {
            $table->foreignId('zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->unsignedSmallInteger('district_id');
            $table->primary(['zone_id', 'district_id']);
            $table->unique('district_id');
            $table->foreign('district_id')->references('id')->on('districts')->restrictOnDelete();
        });

        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 40)->unique();                    // standard, express …
            $table->boolean('cod_available')->default(true);
            $table->bigInteger('cod_fee_minor')->default(0);         // flat fee added to a cash-on-delivery order
            $table->bigInteger('free_over_minor')->nullable();       // order value (after discounts, before shipping) from which delivery is free
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);
        });

        DB::statement('ALTER TABLE shipping_methods ADD CONSTRAINT shipping_methods_money_check CHECK (cod_fee_minor >= 0 AND (free_over_minor IS NULL OR free_over_minor >= 0))');

        Schema::create('shipping_method_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 120);
            $table->string('delivery_estimate', 160)->nullable();   // "1–2 working days"
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['method_id', 'locale']);
        });

        // Rate bands by total parcel weight: the band with the smallest max_weight_g that is >= the weight; NULL = no upper limit.
        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->foreignId('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->unsignedInteger('max_weight_g')->nullable();
            $table->bigInteger('price_minor');
            $table->timestamps(6);

            $table->unique(['zone_id', 'method_id', 'max_weight_g']); // one price per band
            $table->index(['zone_id', 'method_id']);                  // quote: bands of one zone and method
        });

        DB::statement('ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_price_check CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE shipping_rates ADD COLUMN open_band_marker VARCHAR(40) GENERATED ALWAYS AS (IF(max_weight_g IS NULL, CONCAT(zone_id, \'-\', method_id), NULL)) VIRTUAL');
        DB::statement('ALTER TABLE shipping_rates ADD UNIQUE INDEX shipping_rates_one_open_band (open_band_marker)'); // at most one "and above" band per zone and method
    }

    public function down(): void
    {
        foreach (['shipping_rates', 'shipping_method_translations', 'shipping_methods', 'shipping_zone_districts', 'shipping_zone_translations', 'shipping_zones'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
