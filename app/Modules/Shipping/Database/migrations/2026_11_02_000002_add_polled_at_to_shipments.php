<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MUTABLE = ['status', 'courier_consignment_id', 'tracking_code', 'tracking_url', 'attempts', 'handed_over_at', 'delivered_at', 'updated_at'];

    public function up(): void
    {
        // When the courier was last asked about this parcel, so the polling job takes the parcels asked longest ago first and none is starved.
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dateTime('polled_at', 6)->nullable()->after('delivered_at');
            $table->index(['status', 'polled_at']);                    // the polling job: unfinished parcels, least recently asked first
        });

        AppendOnly::protect('shipments', [...self::MUTABLE, 'polled_at']);
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'polled_at']);
            $table->dropColumn('polled_at');
        });
        AppendOnly::protect('shipments', self::MUTABLE);
    }
};
