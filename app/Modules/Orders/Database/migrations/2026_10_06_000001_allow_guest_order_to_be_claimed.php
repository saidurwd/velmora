<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MUTABLE = ['status', 'payment_status', 'fulfillment_status', 'needs_review', 'cancelled_at', 'cancel_reason', 'updated_at', 'user_id'];

    public function up(): void
    {
        // A guest order can be added to the shopper's new account, once. After that the owner never changes, and an order that has
        // an owner can never become a guest order. (The row is otherwise frozen; see AppendOnly.)
        AppendOnly::protect('orders', self::MUTABLE);
        DB::unprepared('DROP TRIGGER IF EXISTS `orders_owner_set_once`');
        DB::unprepared("CREATE TRIGGER `orders_owner_set_once` BEFORE UPDATE ON `orders` FOR EACH ROW BEGIN IF OLD.user_id IS NOT NULL AND NOT (OLD.user_id <=> NEW.user_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: the owner can be set once and never changed'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `orders_owner_set_once`');
        AppendOnly::protect('orders', array_values(array_diff(self::MUTABLE, ['user_id'])));
    }
};
