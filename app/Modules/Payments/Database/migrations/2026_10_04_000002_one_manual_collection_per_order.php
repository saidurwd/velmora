<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One collection per ORDER was too strict: once orders ship in parts, each shipment collects its own cash (and is guarded by
        // UNIQUE(shipment_id)). What must stay single is a hand-recorded collection, which has no shipment: one per order.
        DB::statement('ALTER TABLE cod_collections DROP INDEX cod_collections_order_once');
        DB::statement('ALTER TABLE cod_collections ADD COLUMN manual_marker BIGINT UNSIGNED GENERATED ALWAYS AS (IF(shipment_id IS NULL, order_id, NULL)) VIRTUAL');
        DB::statement('ALTER TABLE cod_collections ADD UNIQUE INDEX cod_collections_one_manual_per_order (manual_marker)');   // two people recording the same delivery by hand: the second is refused

        AppendOnly::protect('cod_collections', ['recorded_by']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cod_collections DROP INDEX cod_collections_one_manual_per_order');
        DB::statement('ALTER TABLE cod_collections DROP COLUMN manual_marker');
        DB::statement('ALTER TABLE cod_collections ADD UNIQUE INDEX cod_collections_order_once (order_id)');
        AppendOnly::protect('cod_collections', ['recorded_by']);
    }
};
