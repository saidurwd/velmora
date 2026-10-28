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
        // Append-only ledger (BD-23): on_hand must always equal the sum of delta per (variant, location).
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->integer('delta');
            $table->string('reason', 24);
            $table->string('ref_type', 30)->nullable();
            $table->string('ref_id', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['variant_id', 'location_id', 'id']); // ledger sum / history of one SKU at one location
            $table->index(['ref_type', 'ref_id']);              // "what moved for this order/shipment"
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_nonzero_check CHECK (delta <> 0)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ('initial','receipt','adjustment','shipment','return_restock','transfer_in','transfer_out','count','write_off'))");
        AppendOnly::protect('stock_movements');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
