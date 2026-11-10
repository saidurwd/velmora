<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Integrity;

use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Platform\Contracts\IntegrityCheck;
use App\Modules\Platform\DTOs\IntegrityResult;

/** The stock held for orders equals the sum of the active reservations behind it. */
final class ReservationsMatchCheck implements IntegrityCheck
{
    public function __construct(private readonly StockLedgerVerifier $verifier) {}

    public function code(): string
    {
        return 'stock_reservations';
    }

    public function run(): IntegrityResult
    {
        $bad = array_values(array_filter($this->verifier->verify(), fn (array $m) => $m['kind'] === 'reserved_vs_reservations'));

        return new IntegrityResult(count($bad), array_map(fn (array $m) => "variant {$m['variant_id']} @ place {$m['location_id']}: reserved {$m['actual']}, active reservations {$m['expected']}", array_slice($bad, 0, (int) config('ops.integrity.sample'))));
    }
}
