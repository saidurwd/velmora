<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MUTABLE = ['status', 'payment_status', 'fulfillment_status', 'needs_review', 'cancelled_at', 'cancel_reason', 'updated_at', 'user_id', 'delivered_at'];

    public function up(): void
    {
        // When a customer is erased, the person's name, phone and email on their past orders must go while the money record stays. The order row stays frozen except
        // for ONE exception: in the single update that stamps `anonymised_at` (once, never changed after), the three contact columns are replaced by the fixed
        // placeholders below, and by nothing else; the stamp cannot be set without them. Amounts, items, dates and numbers can still never change.
        Schema::table('orders', function (Blueprint $table): void {
            $table->dateTime('anonymised_at', 6)->nullable()->after('delivered_at');
        });
        AppendOnly::protect('orders', [...self::MUTABLE, 'contact_name', 'contact_phone', 'contact_email', 'anonymised_at']);
        DB::unprepared('DROP TRIGGER IF EXISTS `orders_erasure_scrub_only`');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER `orders_erasure_scrub_only` BEFORE UPDATE ON `orders` FOR EACH ROW BEGIN
  IF OLD.anonymised_at IS NOT NULL AND NOT (OLD.anonymised_at <=> NEW.anonymised_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: anonymised_at is set once'; END IF;
  IF OLD.anonymised_at IS NOT NULL AND (NOT (OLD.contact_name <=> NEW.contact_name) OR NOT (OLD.contact_phone <=> NEW.contact_phone) OR NOT (OLD.contact_email <=> NEW.contact_email)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: an erased order cannot change its contact'; END IF;
  IF (NOT (OLD.contact_name <=> NEW.contact_name) OR NOT (OLD.contact_phone <=> NEW.contact_phone) OR NOT (OLD.contact_email <=> NEW.contact_email) OR (OLD.anonymised_at IS NULL AND NEW.anonymised_at IS NOT NULL)) THEN
    IF NEW.anonymised_at IS NULL OR NEW.contact_name <> 'Deleted customer' OR NEW.contact_phone <> 'erased' OR NEW.contact_email IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'orders: the contact can only be replaced by the erased placeholders, together with anonymised_at';
    END IF;
  END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `orders_erasure_scrub_only`');
        AppendOnly::protect('orders', self::MUTABLE);
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('anonymised_at');
        });
    }
};
