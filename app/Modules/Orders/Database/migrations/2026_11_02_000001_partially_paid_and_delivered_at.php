<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MUTABLE = ['status', 'payment_status', 'fulfillment_status', 'needs_review', 'cancelled_at', 'cancel_reason', 'updated_at', 'user_id'];

    public function up(): void
    {
        // Cash on delivery is collected parcel by parcel, so an order can be paid in part.
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_payment_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid','pending','partially_paid','paid','partially_refunded','refunded','failed'))");

        // When the last parcel of the order was delivered. Set once; an order that is delivered and paid is completed.
        Schema::table('orders', function (Blueprint $table): void {
            $table->dateTime('delivered_at', 6)->nullable()->after('cancel_reason');
        });

        AppendOnly::protect('orders', [...self::MUTABLE, 'delivered_at']);
        DB::unprepared('DROP TRIGGER IF EXISTS `orders_delivered_set_once`');
        DB::unprepared("CREATE TRIGGER `orders_delivered_set_once` BEFORE UPDATE ON `orders` FOR EACH ROW BEGIN IF OLD.delivered_at IS NOT NULL AND NOT (OLD.delivered_at <=> NEW.delivered_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: delivered_at is set once'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `orders_delivered_set_once`');
        AppendOnly::protect('orders', self::MUTABLE);
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('delivered_at');
        });
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_payment_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid','pending','paid','partially_refunded','refunded','failed'))");
    }
};
