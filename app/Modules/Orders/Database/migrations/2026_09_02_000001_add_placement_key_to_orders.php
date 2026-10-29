<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fingerprint of one checkout submission (sha256 of the shopper + the form's one-time token). UNIQUE, so submitting the same
        // checkout twice, even at the same instant, can only ever make one order; the second finds the first.
        DB::statement('ALTER TABLE orders ADD COLUMN placement_key CHAR(64) NULL AFTER public_id');
        DB::statement('ALTER TABLE orders ADD UNIQUE INDEX orders_placement_key_unique (placement_key)');

        // The freeze-trigger lists the frozen columns when it is built, so rebuild it to include the new one.
        AppendOnly::protect('orders', ['status', 'payment_status', 'fulfillment_status', 'needs_review', 'cancelled_at', 'cancel_reason', 'updated_at']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP INDEX orders_placement_key_unique');
        DB::statement('ALTER TABLE orders DROP COLUMN placement_key');
        AppendOnly::protect('orders', ['status', 'payment_status', 'fulfillment_status', 'needs_review', 'cancelled_at', 'cancel_reason', 'updated_at']);
    }
};
