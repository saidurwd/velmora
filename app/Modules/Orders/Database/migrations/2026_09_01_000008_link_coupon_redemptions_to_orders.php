<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // coupon_redemptions was created before orders existed; the link is added now. UNIQUE(coupon_id, order_id) already indexes order_id's side.
        DB::statement('ALTER TABLE coupon_redemptions ADD INDEX coupon_redemptions_order_id_index (order_id)');   // "which coupons did this order use" (and the FK's index)
        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_order_id_foreign FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE coupon_redemptions DROP FOREIGN KEY coupon_redemptions_order_id_foreign');
        DB::statement('ALTER TABLE coupon_redemptions DROP INDEX coupon_redemptions_order_id_index');
    }
};
