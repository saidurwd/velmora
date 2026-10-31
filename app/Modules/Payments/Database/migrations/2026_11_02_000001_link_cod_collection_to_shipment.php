<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The cash of a delivered parcel points at the parcel (the column existed since Phase 6; the table it points at arrived in Phase 7).
        Schema::table('cod_collections', function (Blueprint $table): void {
            $table->foreign('shipment_id')->references('id')->on('shipments')->restrictOnDelete();
        });

        AppendOnly::protect('cod_collections', ['recorded_by']);
    }

    public function down(): void
    {
        Schema::table('cod_collections', function (Blueprint $table): void {
            $table->dropForeign(['shipment_id']);
        });
    }
};
