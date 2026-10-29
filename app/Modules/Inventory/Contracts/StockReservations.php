<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/** Stock promised to an order. Unlike a cart hold it never times out: it ends when the order ships or is cancelled. */
interface StockReservations
{
    /**
     * All lines or none. Safe against two buyers of the last unit (one conditional UPDATE per line, lines in a fixed order).
     *
     * @param  array<int, int>  $lines  variant id => quantity
     *
     * @throws \DomainException when any line is short; nothing stays reserved
     */
    public function holdForOrder(array $lines, string $orderRef): void;

    /** Gives back everything the order holds. Returns how many holds were released; repeating it is harmless. */
    public function releaseForOrder(string $orderRef): int;
}
