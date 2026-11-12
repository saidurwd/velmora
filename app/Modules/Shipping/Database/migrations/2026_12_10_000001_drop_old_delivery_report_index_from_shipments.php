<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The "contract" step promised when the covering index was added (2026_12_02_000002). `shipments_delivery_report_cover_index` starts with the same two columns
 * (handed_over_at, courier), so every query that could use the old index (handed_over_at, courier) uses the new one just as well, and the report reads from the new one alone.
 * The old one only slowed down writes to shipments. Reversible: down() puts it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex('shipments_handed_over_at_courier_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->index(['handed_over_at', 'courier'], 'shipments_handed_over_at_courier_index');
        });
    }
};
