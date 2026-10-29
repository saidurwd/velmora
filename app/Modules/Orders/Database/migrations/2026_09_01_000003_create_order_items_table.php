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
        // A snapshot of what was bought and at what price (BD-27): later catalog or price edits never change an order.
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();   // reference only
            $table->string('product_type', 12);
            $table->json('name_snapshot');                 // {"en": "...", "bn": "..."}
            $table->string('sku_snapshot', 64);
            $table->json('options_snapshot')->nullable();
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('discount_minor');          // this line's own discount plus its share of any order discount
            $table->unsignedInteger('tax_rate_bp');
            $table->bigInteger('tax_minor');
            $table->bigInteger('line_total_minor');
            $table->unsignedInteger('fulfilled_qty')->default(0);
            $table->unsignedInteger('returned_qty')->default(0);
            $table->timestamps(6);

            $table->index('order_id');                     // the lines of one order
            $table->index('variant_id');                   // "who ordered this SKU" (FK index)
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_check CHECK (quantity > 0 AND fulfilled_qty <= quantity AND returned_qty <= fulfilled_qty)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_money_check CHECK (unit_price_minor >= 0 AND discount_minor >= 0 AND tax_minor >= 0 AND line_total_minor = quantity * unit_price_minor - discount_minor)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_type_check CHECK (product_type IN ('physical','digital','service'))");

        AppendOnly::protect('order_items', ['fulfilled_qty', 'returned_qty', 'updated_at']);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `order_items_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `order_items_no_update`');
        Schema::dropIfExists('order_items');
    }
};
