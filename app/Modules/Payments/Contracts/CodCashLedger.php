<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\CodCashLine;
use App\Modules\Payments\DTOs\CodStatementResult;
use App\Modules\Platform\DTOs\Actor;
use Carbon\CarbonImmutable;

/** The cash side of a courier statement, for Shipping: which parcels' cash we hold a record of, and recording what the courier paid over. */
interface CodCashLedger
{
    /**
     * @param  list<int>  $shipmentIds
     * @return array<int, CodCashLine> keyed by shipment id; a parcel with no recorded cash is absent
     */
    public function forShipments(array $shipmentIds): array;

    /**
     * Records the courier's payment against the parcels it lists: expected is what OUR collections add up to, and what arrived plus what the courier kept
     * must equal it (a short or surplus payment becomes a reconciliation exception for finance).
     *
     * @param  array<string, int>  $courierSays  collection public id => what the courier reports for that parcel
     */
    public function recordStatement(Actor $actor, string $courier, string $statementRef, int $receivedMinor, int $feeMinor, ?CarbonImmutable $receivedAt, array $courierSays): CodStatementResult;
}
