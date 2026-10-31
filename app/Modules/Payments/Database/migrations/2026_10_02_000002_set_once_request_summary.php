<?php

declare(strict_types=1);

use App\Modules\Payments\Support\StatusFlow;
use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const FLOW = [
        'initiated' => ['pending', 'succeeded', 'failed', 'cancelled', 'expired'],
        'pending' => ['succeeded', 'failed', 'cancelled', 'expired'],
    ];

    public function up(): void
    {
        // What the gateway answered when the attempt was started is recorded once, after the call. It can be filled in (NULL → value) and never rewritten.
        AppendOnly::protect('payment_transactions', ['status', 'provider_session', 'provider_validation_id', 'provider_txn_id', 'verified_at', 'failure_code', 'request_summary', 'updated_at']);
        StatusFlow::install('payment_transactions', 'status', self::FLOW, setOnce: ['provider_txn_id', 'provider_validation_id', 'request_summary']);
    }

    public function down(): void
    {
        AppendOnly::protect('payment_transactions', ['status', 'provider_session', 'provider_validation_id', 'provider_txn_id', 'verified_at', 'failure_code', 'updated_at']);
        StatusFlow::install('payment_transactions', 'status', self::FLOW, setOnce: ['provider_txn_id', 'provider_validation_id']);
    }
};
