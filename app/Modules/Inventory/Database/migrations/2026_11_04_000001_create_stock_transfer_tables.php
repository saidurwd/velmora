<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Goods are on their way until they are received or the transfer is cancelled. */
    private const FLOW = ['in_transit' => ['received', 'cancelled']];

    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('from_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('status', 12)->default('in_transit');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->dateTime('received_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'created_at']);                   // transfers still on their way
            $table->index('from_location_id');
            $table->index('to_location_id');
        });

        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_check CHECK (from_location_id <> to_location_id AND status IN ('in_transit','received','cancelled') AND (status <> 'received' OR received_at IS NOT NULL) AND (status <> 'cancelled' OR cancelled_at IS NOT NULL))");
        AppendOnly::protect('stock_transfers', ['status', 'received_by', 'received_at', 'cancelled_at', 'updated_at']);
        StatusFlow::install('stock_transfers', 'status', self::FLOW);

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('sent_qty');
            $table->unsignedInteger('received_qty')->nullable();       // set once, at receipt
            $table->unsignedInteger('lost_qty')->nullable();           // set once: what did not arrive
            $table->dateTime('created_at', 6);

            $table->unique(['transfer_id', 'variant_id']);             // one line per product in a transfer
            $table->index('variant_id');
        });

        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_check CHECK (sent_qty > 0 AND ((received_qty IS NULL AND lost_qty IS NULL) OR (received_qty IS NOT NULL AND lost_qty IS NOT NULL AND received_qty + lost_qty = sent_qty)))');
        AppendOnly::protect('stock_transfer_items', ['received_qty', 'lost_qty']);
        DB::unprepared("CREATE TRIGGER `stock_transfer_items_set_once` BEFORE UPDATE ON `stock_transfer_items` FOR EACH ROW BEGIN IF (OLD.received_qty IS NOT NULL AND NOT (OLD.received_qty <=> NEW.received_qty)) OR (OLD.lost_qty IS NOT NULL AND NOT (OLD.lost_qty <=> NEW.lost_qty)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_transfer_items: what arrived is set once'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_transfer_items_set_once`');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_transfer_items_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_transfer_items_no_update`');
        Schema::dropIfExists('stock_transfer_items');
        StatusFlow::remove('stock_transfers');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_transfers_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_transfers_no_update`');
        Schema::dropIfExists('stock_transfers');
    }
};
