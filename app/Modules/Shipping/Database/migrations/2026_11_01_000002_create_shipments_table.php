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
    /** The parcel's life. A parcel can skip scans but never goes back; delivered, returned and cancelled are final; only an unhanded parcel can be cancelled. */
    private const FLOW = [
        'created' => ['handed_over', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'cancelled', 'unknown'],
        'handed_over' => ['in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned', 'unknown'],
        'in_transit' => ['out_for_delivery', 'delivered', 'failed', 'returned', 'unknown'],
        'out_for_delivery' => ['in_transit', 'delivered', 'failed', 'returned', 'unknown'],
        'failed' => ['in_transit', 'out_for_delivery', 'delivered', 'returned', 'unknown'],
        'unknown' => ['handed_over', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'],
    ];

    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('courier_account_id')->constrained('courier_accounts')->restrictOnDelete();
            $table->string('courier', 30);                             // copied from the account: the history stays right if the account is renamed
            $table->string('courier_consignment_id', 80)->nullable();  // the courier's id for the parcel (set once)
            $table->string('tracking_code', 80)->nullable();
            $table->string('tracking_url', 255)->nullable();
            $table->string('status', 20)->default('created');
            $table->bigInteger('cod_amount_minor')->default(0);        // the cash this parcel collects at the door (0 = prepaid)
            $table->char('currency', 3)->default('BDT');
            $table->unsignedSmallInteger('attempts')->default(0);      // delivery attempts that failed
            $table->dateTime('handed_over_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['courier', 'courier_consignment_id']);     // a courier's parcel belongs to one shipment (several NULLs are allowed)
            $table->index(['order_id', 'status']);                     // the parcels of one order; the order's fulfilment state
            $table->index(['status', 'updated_at']);                   // the exception queue: parcels stuck in a state
            $table->index('tracking_code');                            // finding a parcel by the code on the label
        });

        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('created','handed_over','in_transit','out_for_delivery','delivered','failed','returned','cancelled','unknown'))");
        DB::statement('ALTER TABLE shipments ADD CONSTRAINT shipments_amounts_check CHECK (cod_amount_minor >= 0)');
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_delivered_check CHECK (status <> 'delivered' OR delivered_at IS NOT NULL)");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_handover_check CHECK (status IN ('created','cancelled') OR handed_over_at IS NOT NULL)");

        AppendOnly::protect('shipments', ['status', 'courier_consignment_id', 'tracking_code', 'tracking_url', 'attempts', 'handed_over_at', 'delivered_at', 'updated_at']);
        StatusFlow::install('shipments', 'status', self::FLOW, setOnce: ['courier_consignment_id']);

        // What is in each parcel. Never changed or deleted: a wrong parcel is cancelled and a new one made.
        Schema::create('shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->dateTime('created_at', 6);

            $table->unique(['shipment_id', 'order_item_id']);          // one line per order line in a parcel
            $table->index('order_item_id');                            // which parcels carry an order line
        });

        DB::statement('ALTER TABLE shipment_items ADD CONSTRAINT shipment_items_qty_check CHECK (quantity > 0)');
        AppendOnly::protect('shipment_items');

        // Everything a courier (or staff) ever said about a parcel, exactly as received. The parcel's state is worked out from these by the
        // time the courier gave, not by the order they arrived in. Never changed or deleted.
        Schema::create('shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->string('provider_status', 80);                     // the courier's own word
            $table->string('internal_status', 20);                     // what it means to us
            $table->dateTime('occurred_at', 6);                        // when it happened (the courier's time)
            $table->dateTime('received_at', 6);                        // when we heard
            $table->string('source', 10);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->json('summary')->nullable();                       // the courier's message without secrets or the customer's details

            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['shipment_id', 'provider_status', 'occurred_at']);   // the same event delivered twice is stored once
            $table->index(['shipment_id', 'occurred_at', 'id']);       // the timeline of one parcel, in the courier's time
        });

        DB::statement("ALTER TABLE shipment_events ADD CONSTRAINT shipment_events_check CHECK (source IN ('webhook','poll','manual','system') AND internal_status IN ('created','handed_over','in_transit','out_for_delivery','delivered','failed','returned','cancelled','unknown'))");
        AppendOnly::protect('shipment_events');
    }

    public function down(): void
    {
        foreach (['shipment_events', 'shipment_items'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_delete`");
            DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_update`");
            Schema::dropIfExists($table);
        }
        StatusFlow::remove('shipments');
        DB::unprepared('DROP TRIGGER IF EXISTS `shipments_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `shipments_no_update`');
        Schema::dropIfExists('shipments');
    }
};
