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
        // What the lines of the orders placed on one (Dhaka) day add up to, per product variant and the same groups as the sales rows, so reports can be narrowed the same way.
        // No foreign key to the catalogue: a row outlives a variant that is removed later (the sku is the one written on the order line).
        Schema::create('report_daily_products', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('variant_id');
            $table->string('sku', 64);
            $table->string('channel', 10);
            $table->string('payment_method', 30);
            $table->char('locale', 2);
            $table->char('currency', 3);
            $table->unsignedInteger('orders');
            $table->unsignedInteger('units');
            $table->unsignedInteger('returned_units');
            $table->bigInteger('gross_minor');
            $table->bigInteger('discounts_minor');
            $table->dateTime('refreshed_at', 6);

            $table->unique(['day', 'variant_id', 'channel', 'payment_method', 'locale', 'currency'], 'report_daily_products_group_unique');   // one row per day and group; reports read a range of days: where day between ? and ?
        });

        // The tax billed on one day's orders, per kind of charge and rate.
        Schema::create('report_daily_tax', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('kind', 10);                  // goods | shipping | cod_fee
            $table->unsignedSmallInteger('rate_bp');
            $table->char('currency', 3);
            $table->boolean('tax_inclusive');
            $table->unsignedInteger('lines');
            $table->bigInteger('taxable_minor');
            $table->bigInteger('tax_minor');
            $table->dateTime('refreshed_at', 6);

            $table->unique(['day', 'kind', 'rate_bp', 'currency', 'tax_inclusive'], 'report_daily_tax_group_unique');
        });

        DB::statement('ALTER TABLE report_daily_products ADD CONSTRAINT report_daily_products_amounts_check CHECK (gross_minor >= 0 AND discounts_minor >= 0 AND returned_units <= units)');
        DB::statement("ALTER TABLE report_daily_tax ADD CONSTRAINT report_daily_tax_check CHECK (kind IN ('goods','shipping','cod_fee') AND taxable_minor >= 0 AND tax_minor >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_daily_tax');
        Schema::dropIfExists('report_daily_products');
    }
};
