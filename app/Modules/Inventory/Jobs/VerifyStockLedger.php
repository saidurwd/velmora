<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Nightly integrity check. A mismatch is a CRITICAL alert: never auto-corrected, a human investigates. */
class VerifyStockLedger implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(StockLedgerVerifier $verifier, AuditLogger $audit): void
    {
        $mismatches = $verifier->verify();

        foreach ($mismatches as $m) {
            Log::critical('inventory.ledger_mismatch', $m);
            $audit->record('inventory.ledger_mismatch', 'variant', after: $m, actor: Actor::system('integrity-check'), subjectId: $m['variant_id']);
        }
    }
}
