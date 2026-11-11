<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Found by the Phase 10 scale test on 550,000 parcels. The delivery performance report adds up the parcels handed over in a period and needs, for each one,
 * its courier, status, attempts, delivery time, currency and cash amount. The old index (handed_over_at, courier) found the parcels but the database still had to fetch
 * every row, so it chose to read the whole table (1.2 s for a month, 2.4 s for a year). This index holds all those columns, so the report is read from the index alone.
 * The old index is kept (expand first); a later migration may drop it once nothing needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->index(['handed_over_at', 'courier', 'status', 'attempts', 'delivered_at', 'currency', 'cod_amount_minor'], 'shipments_delivery_report_cover_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex('shipments_delivery_report_cover_index');
        });
    }
};
