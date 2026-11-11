<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Found by the Phase 10 scale test on one million orders. Only adds indexes (nothing is changed or removed), so it is safe on a live table.
 *   - orders_status_fulfillment_created_at_index: "orders to ship" (status = confirmed AND fulfillment_status = unfulfilled) on the dashboard and the orders list filter.
 *     It read every confirmed order's row (1.8 s); with the second column it counts from the index alone.
 *   - orders_cod_awaiting_index: the cash-on-delivery aging report reads orders where payment_method_code = cod, payment_status = unpaid and status in (...), oldest first.
 *     Without it the report read the whole table (3.6 s).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'fulfillment_status', 'created_at'], 'orders_status_fulfillment_created_at_index');
            $table->index(['payment_method_code', 'payment_status', 'status', 'created_at'], 'orders_cod_awaiting_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_status_fulfillment_created_at_index');
            $table->dropIndex('orders_cod_awaiting_index');
        });
    }
};
