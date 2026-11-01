<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Actions\RecordCodRemittance;
use App\Modules\Payments\Contracts\CodCashLedger;
use App\Modules\Payments\DTOs\CodCashLine;
use App\Modules\Payments\DTOs\CodStatementResult;
use App\Modules\Platform\DTOs\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseCodCashLedger implements CodCashLedger
{
    public function __construct(private readonly RecordCodRemittance $remit) {}

    public function forShipments(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $out = [];
        // serves: the cash recorded for a set of parcels, and whether a courier statement already covered it (UNIQUE cod_remittance_items.cod_collection_id)
        foreach (DB::table('cod_collections as c')->whereIn('c.shipment_id', $shipmentIds)->selectRaw('c.public_id, c.shipment_id, c.amount_minor, EXISTS (SELECT 1 FROM cod_remittance_items i WHERE i.cod_collection_id = c.id) AS remitted')->get() as $row) {
            $out[(int) $row->shipment_id] = new CodCashLine((string) $row->public_id, (int) $row->shipment_id, (int) $row->amount_minor, (bool) $row->remitted);
        }

        return $out;
    }

    public function recordStatement(Actor $actor, string $courier, string $statementRef, int $receivedMinor, int $feeMinor, ?CarbonImmutable $receivedAt, array $courierSays): CodStatementResult
    {
        $items = [];
        foreach ($courierSays as $collection => $amount) {
            $items[] = ['collection' => (string) $collection, 'amount_minor' => $amount];
        }
        $remittance = $this->remit->handle($actor, $courier, $statementRef, $receivedMinor, $receivedAt, $items, $feeMinor);

        return new CodStatementResult($remittance->public_id, $remittance->status, $remittance->expected_minor);
    }
}
