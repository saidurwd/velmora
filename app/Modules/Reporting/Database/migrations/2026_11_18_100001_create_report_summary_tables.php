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
        // What the orders placed on one (Dhaka) day add up to, per channel, payment method, language and currency. Worked out again, never added to: a day is replaced as a whole.
        Schema::create('report_daily_sales', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('channel', 10);
            $table->string('payment_method', 30);
            $table->char('locale', 2);
            $table->char('currency', 3);
            $table->unsignedInteger('orders');
            $table->bigInteger('gross_minor');
            $table->bigInteger('discounts_minor');
            $table->bigInteger('shipping_minor');
            $table->bigInteger('cod_fee_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('billed_minor');
            $table->unsignedInteger('cancelled_orders');
            $table->bigInteger('cancelled_billed_minor');
            $table->dateTime('refreshed_at', 6);

            $table->unique(['day', 'channel', 'payment_method', 'locale', 'currency'], 'report_daily_sales_group_unique');   // one row per day and group; a report reads a range of days: where day between ? and ?
        });

        // The money that came in and went out on one day, grouped by how the ORDER was placed (channel, language), so a report can be filtered the same way as sales.
        Schema::create('report_daily_payments', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('channel', 10);
            $table->string('payment_method', 30);
            $table->char('locale', 2);
            $table->char('currency', 3);
            $table->bigInteger('collected_minor');
            $table->unsignedInteger('collected_count');
            $table->bigInteger('refunded_minor');
            $table->bigInteger('refunded_live_minor');   // the part of the refunds that belongs to orders that were not cancelled: only that part lowers net sales
            $table->unsignedInteger('refund_count');
            $table->dateTime('refreshed_at', 6);

            $table->unique(['day', 'channel', 'payment_method', 'locale', 'currency'], 'report_daily_payments_group_unique');   // same, for money
        });

        // When each day was last worked out, and by what: the screen says which days are missing or old.
        Schema::create('report_day_status', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->dateTime('refreshed_at', 6);
            $table->string('trigger', 10);          // schedule | nightly | rebuild
            $table->unsignedInteger('sales_rows');
            $table->unsignedInteger('payment_rows');
        });

        foreach (['report_daily_sales' => 'orders >= 0 AND gross_minor >= 0 AND discounts_minor >= 0 AND shipping_minor >= 0 AND cod_fee_minor >= 0 AND tax_minor >= 0 AND billed_minor >= 0 AND cancelled_billed_minor >= 0',
            'report_daily_payments' => 'collected_minor >= 0 AND refunded_minor >= 0 AND refunded_live_minor >= 0 AND refunded_live_minor <= refunded_minor'] as $table => $rule) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_amounts_check CHECK ({$rule})");
        }
        DB::statement("ALTER TABLE report_day_status ADD CONSTRAINT report_day_status_trigger_check CHECK (`trigger` IN ('schedule','nightly','rebuild'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_day_status');
        Schema::dropIfExists('report_daily_payments');
        Schema::dropIfExists('report_daily_sales');
    }
};
