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
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();               // the only id that leaves the system (confirmation links, API)
            $table->string('order_number', 20)->unique();          // VM-YYMM-NNNNNN, what customers quote
            $table->unsignedBigInteger('user_id')->nullable();     // NULL = guest checkout
            $table->string('contact_name', 150);
            $table->string('contact_phone', 20);                   // E.164
            $table->string('contact_email', 190)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('payment_status', 24)->default('unpaid');
            $table->string('fulfillment_status', 24)->default('unfulfilled');
            $table->boolean('needs_review')->default(false);       // COD abuse rules (slice 8) park an order here
            $table->string('channel', 10)->default('web');
            $table->string('locale', 5);
            $table->char('currency', 3)->default('BDT');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('shipping_minor');
            $table->bigInteger('cod_fee_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('grand_total_minor');
            $table->boolean('tax_inclusive');
            $table->string('payment_method_code', 30);
            $table->string('shipping_method_code', 30)->nullable();
            $table->dateTime('placed_at', 6);
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps(6);

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['user_id', 'created_at']);              // "my orders"
            $table->index(['status', 'created_at']);               // admin queue by status
            $table->index(['payment_status', 'created_at']);       // admin queue by payment state
            $table->index('contact_phone');                        // support search; open-COD-orders-per-phone rule
            $table->index('needs_review');                         // admin "needs review" queue
        });

        $checks = [
            'status' => "status IN ('pending','confirmed','processing','completed','cancelled')",
            'payment_status' => "payment_status IN ('unpaid','pending','paid','partially_refunded','refunded','failed')",
            'fulfillment_status' => "fulfillment_status IN ('unfulfilled','partially_fulfilled','fulfilled','partially_returned','returned')",
            'channel' => "channel IN ('web','api','admin')",
            'amounts' => 'subtotal_minor >= 0 AND discount_minor >= 0 AND shipping_minor >= 0 AND cod_fee_minor >= 0 AND tax_minor >= 0 AND grand_total_minor >= 0',
            // BANGLADESH_LOCALIZATION §5: the parts always add up to the total. Tax is added only when prices exclude it.
            'total' => 'grand_total_minor = subtotal_minor - discount_minor + shipping_minor + cod_fee_minor + IF(tax_inclusive, 0, tax_minor)',
            'cancel' => "(status = 'cancelled') = (cancelled_at IS NOT NULL)",
        ];
        foreach ($checks as $name => $sql) {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_{$name}_check CHECK ({$sql})");
        }

        // Money, who, what and when are frozen by the database; only the state columns may change.
        AppendOnly::protect('orders', ['status', 'payment_status', 'fulfillment_status', 'needs_review', 'cancelled_at', 'cancel_reason', 'updated_at']);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `orders_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `orders_no_update`');
        Schema::dropIfExists('orders');
    }
};
