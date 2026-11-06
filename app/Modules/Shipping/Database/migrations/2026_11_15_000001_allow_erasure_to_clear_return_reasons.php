<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MUTABLE = ['status', 'decided_by', 'decided_at', 'decision_note', 'shipping_cost_payer', 'refund_suggested_minor', 'received_at', 'received_by', 'closed_at', 'updated_at'];

    public function up(): void
    {
        // The words a customer typed when asking to return something are personal. When they are erased those words must be removable, while the return itself (what, how
        // many, when, who decided) stays. The `reason` column may be CLEARED (set to NULL) and never changed to anything else; every other frozen column stays frozen.
        AppendOnly::protect('return_requests', [...self::MUTABLE, 'reason']);
        DB::unprepared('DROP TRIGGER IF EXISTS `return_requests_reason_clear_only`');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER `return_requests_reason_clear_only` BEFORE UPDATE ON `return_requests` FOR EACH ROW BEGIN
  IF NOT (OLD.reason <=> NEW.reason) AND NEW.reason IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'return_requests: the customer''s words can only be cleared';
  END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `return_requests_reason_clear_only`');
        AppendOnly::protect('return_requests', self::MUTABLE);
    }
};
