<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = "'initial','receipt','adjustment','shipment','return_restock','transfer_in','transfer_out','count','write_off'";

    public function up(): void
    {
        // A shipment cancelled before handover puts its stock back: that is its own ledger reason, so "what moved for this shipment" reads true.
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_reason_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ('.self::OLD.",'shipment_cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_reason_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ('.self::OLD.'))');
    }
};
