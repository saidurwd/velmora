<?php

declare(strict_types=1);

use App\Modules\Payments\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const BEFORE = [
        'initiated' => ['pending', 'succeeded', 'failed', 'cancelled', 'expired'],
        'pending' => ['succeeded', 'failed', 'cancelled', 'expired'],
    ];

    private const AFTER = self::BEFORE + [
        'failed' => ['succeeded'],      // the gateway can report a success after an earlier failed try in the same session
        'cancelled' => ['succeeded'],
        'expired' => ['succeeded'],
    ];

    public function up(): void
    {
        StatusFlow::install('payment_transactions', 'status', self::AFTER, setOnce: ['provider_txn_id', 'provider_validation_id', 'request_summary']);
    }

    public function down(): void
    {
        StatusFlow::install('payment_transactions', 'status', self::BEFORE, setOnce: ['provider_txn_id', 'provider_validation_id', 'request_summary']);
    }
};
