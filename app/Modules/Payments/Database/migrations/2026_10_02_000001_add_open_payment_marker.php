<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // At most one payment per order is "open" (started, waiting, or held for a person). Two checkouts racing to start payment for the
        // same order cannot both create one: the second is refused by the database and reuses the first. A failed, cancelled or
        // expired payment is not open; a failed or cancelled one is retried (new attempt on the same payment) rather than replaced.
        DB::statement("ALTER TABLE payments ADD COLUMN open_marker BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('initiated','pending','needs_review'), order_id, NULL)) VIRTUAL");
        DB::statement('ALTER TABLE payments ADD UNIQUE INDEX payments_one_open_per_order (open_marker)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP INDEX payments_one_open_per_order');
        DB::statement('ALTER TABLE payments DROP COLUMN open_marker');
    }
};
