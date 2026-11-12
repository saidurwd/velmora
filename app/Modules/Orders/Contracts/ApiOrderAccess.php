<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\OrderAccess;
use Illuminate\Http\Request;

/** Who may act on an order from the app. Another person's order and a missing one are the same answer (null), so a caller can never tell them apart. */
interface ApiOrderAccess
{
    public const HEADER = 'X-Order-Token';

    /** The order, when the caller is its signed-in owner or holds a valid, unexpired token for exactly this order. */
    public function find(Request $request, string $orderPublicId): ?OrderAccess;

    /** A token that lets the holder pay for, view and track this one order (given to a guest when the order is placed). */
    public function issueToken(string $orderPublicId): string;
}
