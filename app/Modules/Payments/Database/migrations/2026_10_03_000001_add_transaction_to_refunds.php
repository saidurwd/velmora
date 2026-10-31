<?php

declare(strict_types=1);

use App\Modules\Payments\Support\StatusFlow;
use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FLOW = [
        'requested' => ['approved', 'rejected'],
        'approved' => ['processing', 'succeeded', 'failed'],
        'processing' => ['succeeded', 'failed'],
    ];

    public function up(): void
    {
        // Which paid attempt a refund gives money back from. Needed to give back a DUPLICATE payment (a second attempt that was also paid):
        // that money is not part of the payment's `captured_minor`, so it is limited by the attempt's own amount instead. Set when the refund is asked for, never changed.
        DB::statement('ALTER TABLE refunds ADD COLUMN payment_transaction_id BIGINT UNSIGNED NULL AFTER order_id');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_payment_transaction_id_foreign FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE refunds ADD INDEX refunds_tx_status_index (payment_transaction_id, status)'); // the over-refund guard for one attempt: sum of its live refunds

        // the triggers list their frozen columns when built: rebuild so the new column is frozen too
        AppendOnly::protect('refunds', ['status', 'provider_refund_id', 'approved_by', 'proof_ref', 'failure_reason', 'updated_at']);
        StatusFlow::install('refunds', 'status', self::FLOW, setOnce: ['provider_refund_id']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP FOREIGN KEY refunds_payment_transaction_id_foreign');
        DB::statement('ALTER TABLE refunds DROP INDEX refunds_tx_status_index');
        DB::statement('ALTER TABLE refunds DROP COLUMN payment_transaction_id');
        AppendOnly::protect('refunds', ['status', 'provider_refund_id', 'approved_by', 'proof_ref', 'failure_reason', 'updated_at']);
        StatusFlow::install('refunds', 'status', self::FLOW, setOnce: ['provider_refund_id']);
    }
};
