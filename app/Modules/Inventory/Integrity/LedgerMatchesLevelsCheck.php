<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Integrity;

use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Platform\Contracts\IntegrityCheck;
use App\Modules\Platform\DTOs\IntegrityResult;

/** The stock on hand of every item and place equals the sum of its ledger movements, and no movement points at a place with no stock row. */
final class LedgerMatchesLevelsCheck implements IntegrityCheck
{
    public function __construct(private readonly StockLedgerVerifier $verifier) {}

    public function code(): string
    {
        return 'stock_ledger';
    }

    public function run(): IntegrityResult
    {
        $bad = array_values(array_filter($this->verifier->verify(), fn (array $m) => $m['kind'] !== 'reserved_vs_reservations'));

        return new IntegrityResult(count($bad), array_map(fn (array $m) => "variant {$m['variant_id']} @ place {$m['location_id']}: {$m['kind']} (expected {$m['expected']}, found {$m['actual']})", array_slice($bad, 0, (int) config('ops.integrity.sample'))));
    }
}
