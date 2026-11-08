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
        // Customer cohorts: signed-in customers grouped by the shop month of their first live order. One row per cohort and "months since" (0 = the first month): how many of the
        // cohort ordered that month, and their orders and goods price after discounts. Rebuilt whole every night. Holds numbers only, never a customer.
        Schema::create('report_cohort_months', function (Blueprint $table): void {
            $table->id();
            $table->char('cohort_month', 7);                                   // Y-m
            $table->unsignedSmallInteger('months_since');
            $table->unsignedInteger('customers');
            $table->unsignedInteger('orders');
            $table->bigInteger('sales_minor');
            $table->dateTime('refreshed_at', 6);

            $table->unique(['cohort_month', 'months_since'], 'report_cohort_months_unique');   // the cohort screen reads a range of cohort months, in order
        });
        DB::statement('ALTER TABLE report_cohort_months ADD CONSTRAINT report_cohort_months_check CHECK (customers > 0 AND orders >= customers AND sales_minor >= 0 AND months_since < 1200)');

        // One row: the rebuild locks it, so two rebuilds take turns and the ledgers are read only after getting the lock.
        Schema::create('report_cohort_status', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->dateTime('refreshed_at', 6);
            $table->unsignedInteger('cohort_rows')->default(0);
            $table->unsignedInteger('customers')->default(0);
        });
        DB::statement('ALTER TABLE report_cohort_status ADD CONSTRAINT report_cohort_status_check CHECK (id = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_cohort_status');
        Schema::dropIfExists('report_cohort_months');
    }
};
