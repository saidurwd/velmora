<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The delivery performance report adds up the parcels handed over in a period, per courier.
        Schema::table('shipments', function (Blueprint $table): void {
            $table->index(['handed_over_at', 'courier'], 'shipments_handed_over_at_courier_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex('shipments_handed_over_at_courier_index');
        });
    }
};
