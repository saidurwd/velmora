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
    /** A return is asked for, allowed or refused, the goods arrive, and every line is looked at. */
    private const FLOW = [
        'requested' => ['authorized', 'rejected', 'cancelled'],
        'authorized' => ['received', 'cancelled'],
        'received' => ['closed'],
    ];

    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->restrictOnDelete();
            $table->string('kind', 10);                                // customer: the customer sends goods back; origin: the courier brings an undelivered parcel back
            $table->string('status', 12)->default('requested');
            $table->string('reason_code', 30);
            $table->string('reason', 255)->nullable();
            $table->string('requested_by_type', 10);                   // customer | staff | system
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->string('shipping_cost_payer', 8)->default('none'); // who pays for sending the goods back: recorded, never charged automatically
            $table->bigInteger('refund_suggested_minor')->nullable();  // what the goods that came back were worth: a suggestion for a person, never executed
            $table->char('currency', 3)->default('BDT');
            $table->dateTime('received_at', 6)->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['order_id', 'status']);                     // the returns of one order; what is still open for it
            $table->index(['status', 'created_at']);                   // the returns queue
            $table->index('shipment_id');                              // the returns of one parcel
        });

        // an undelivered parcel that comes back is opened as a return once
        DB::statement("ALTER TABLE return_requests ADD COLUMN origin_marker BIGINT UNSIGNED GENERATED ALWAYS AS (IF(kind = 'origin', shipment_id, NULL)) VIRTUAL");
        DB::statement('ALTER TABLE return_requests ADD UNIQUE INDEX return_requests_one_origin_per_parcel (origin_marker)');

        DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_check CHECK (kind IN ('customer','origin') AND status IN ('requested','authorized','rejected','received','closed','cancelled') AND shipping_cost_payer IN ('shop','customer','none') AND requested_by_type IN ('customer','staff','system') AND (refund_suggested_minor IS NULL OR refund_suggested_minor >= 0))");
        DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_closed_check CHECK (status <> 'closed' OR (closed_at IS NOT NULL AND received_at IS NOT NULL AND refund_suggested_minor IS NOT NULL))");

        AppendOnly::protect('return_requests', ['status', 'decided_by', 'decided_at', 'decision_note', 'shipping_cost_payer', 'refund_suggested_minor', 'received_at', 'received_by', 'closed_at', 'updated_at']);
        StatusFlow::install('return_requests', 'status', self::FLOW);

        Schema::create('return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_id')->constrained('return_requests')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');                       // asked for
            $table->unsignedInteger('received_qty')->nullable();       // what actually came back (set once)
            $table->string('outcome', 12)->nullable();                 // set once, after the goods are looked at
            $table->unsignedBigInteger('inspected_by')->nullable();
            $table->dateTime('inspected_at', 6)->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('inspected_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['return_id', 'order_item_id']);            // one line per order line in a return
            $table->index('order_item_id');                            // how much of an order line is out on returns
        });

        DB::statement("ALTER TABLE return_items ADD CONSTRAINT return_items_check CHECK (quantity > 0 AND (received_qty IS NULL OR received_qty <= quantity) AND (outcome IS NULL OR outcome IN ('restock','quarantine','write_off','not_received')) AND (outcome IS NULL OR received_qty IS NOT NULL))");
        AppendOnly::protect('return_items', ['received_qty', 'outcome', 'inspected_by', 'inspected_at', 'note']);
        DB::unprepared("CREATE TRIGGER `return_items_set_once` BEFORE UPDATE ON `return_items` FOR EACH ROW BEGIN IF (OLD.received_qty IS NOT NULL AND NOT (OLD.received_qty <=> NEW.received_qty)) OR (OLD.outcome IS NOT NULL AND NOT (OLD.outcome <=> NEW.outcome)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return_items: what came back and what was decided are set once'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `return_items_set_once`');
        DB::unprepared('DROP TRIGGER IF EXISTS `return_items_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `return_items_no_update`');
        Schema::dropIfExists('return_items');
        StatusFlow::remove('return_requests');
        DB::unprepared('DROP TRIGGER IF EXISTS `return_requests_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `return_requests_no_update`');
        Schema::dropIfExists('return_requests');
    }
};
