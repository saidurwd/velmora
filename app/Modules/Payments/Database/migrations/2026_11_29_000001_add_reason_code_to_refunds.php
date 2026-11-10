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
        // Why the money went back, as a code the reports can group by. NULL for refunds made before this column existed (never guessed from the free text).
        // Set when the refund is asked for, never changed. Report query: succeeded refunds in a period, grouped by code, uses the existing index refunds(status, updated_at).
        DB::statement('ALTER TABLE refunds ADD COLUMN reason_code VARCHAR(30) NULL AFTER reason');
        $codes = implode("','", self::codes());
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_reason_code_check CHECK (reason_code IS NULL OR reason_code IN ('{$codes}'))");

        // the triggers list their frozen columns when built: rebuild so the new column is frozen too
        AppendOnly::protect('refunds', ['status', 'provider_refund_id', 'approved_by', 'proof_ref', 'failure_reason', 'updated_at']);
        StatusFlow::install('refunds', 'status', self::FLOW, setOnce: ['provider_refund_id']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_reason_code_check');
        DB::statement('ALTER TABLE refunds DROP COLUMN reason_code');
        AppendOnly::protect('refunds', ['status', 'provider_refund_id', 'approved_by', 'proof_ref', 'failure_reason', 'updated_at']);
        StatusFlow::install('refunds', 'status', self::FLOW, setOnce: ['provider_refund_id']);
    }

    /** @return list<string> the codes as they are today; a later code needs its own migration */
    private static function codes(): array
    {
        return ['customer_changed_mind', 'item_returned', 'item_damaged', 'wrong_item', 'not_delivered', 'price_adjustment', 'duplicate_payment', 'fraud_or_risk', 'other'];
    }
};
