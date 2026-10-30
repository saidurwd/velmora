<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash a courier (or staff) has collected from the customer. Never changed or deleted.
        Schema::create('cod_collections', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('shipment_id')->nullable();    // the shipment's FK arrives with shipments (Phase 7)
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('BDT');
            $table->string('source', 10);                              // courier | manual
            $table->dateTime('collected_at', 6);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('shipment_id');                             // a shipment's cash is collected once
            $table->index('order_id');                                 // what has been collected for an order
            $table->index('collected_at');                             // aging: collected but not yet remitted
        });

        DB::statement("ALTER TABLE cod_collections ADD CONSTRAINT cod_collections_check CHECK (amount_minor > 0 AND source IN ('courier','manual'))");
        AppendOnly::protect('cod_collections', ['recorded_by']);

        // Money a courier has paid over to the shop, usually against a statement listing many parcels.
        Schema::create('cod_remittances', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('courier', 40);                             // courier name (accounts arrive with couriers in Phase 7)
            $table->string('statement_ref', 80);
            $table->bigInteger('amount_minor');                        // what actually arrived
            $table->bigInteger('expected_minor')->default(0);          // what the listed collections add up to
            $table->char('currency', 3)->default('BDT');
            $table->string('status', 12)->default('pending');
            $table->dateTime('received_at', 6);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps(6);

            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['courier', 'statement_ref']);              // the same statement entered twice is refused
            $table->index(['status', 'received_at']);                  // unreconciled and short remittances
        });

        DB::statement("ALTER TABLE cod_remittances ADD CONSTRAINT cod_remittances_check CHECK (amount_minor >= 0 AND expected_minor >= 0 AND status IN ('pending','reconciled','short','over'))");

        Schema::create('cod_remittance_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remittance_id')->constrained('cod_remittances')->restrictOnDelete();
            $table->foreignId('cod_collection_id')->constrained('cod_collections')->restrictOnDelete();
            $table->bigInteger('amount_minor');                        // what the courier reported for this parcel
            $table->unique('cod_collection_id');                       // a collection is remitted once
            $table->index('remittance_id');
        });

        DB::statement('ALTER TABLE cod_remittance_items ADD CONSTRAINT cod_remittance_items_check CHECK (amount_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_remittance_items');
        Schema::dropIfExists('cod_remittances');
        DB::unprepared('DROP TRIGGER IF EXISTS `cod_collections_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `cod_collections_no_update`');
        Schema::dropIfExists('cod_collections');
    }
};
