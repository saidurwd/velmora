<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Contracts\StockReservations;

final class OrderStockReservations implements StockReservations
{
    public const OWNER = 'order';

    public function __construct(private readonly ReserveStock $reserve, private readonly ReleaseReservation $release) {}

    public function holdForOrder(array $lines, string $orderRef): void
    {
        if ($lines !== []) {
            $this->reserve->forLines($lines, self::OWNER, $orderRef, 0);
        }
    }

    public function releaseForOrder(string $orderRef): int
    {
        return $this->release->forOwner(self::OWNER, $orderRef);
    }
}
