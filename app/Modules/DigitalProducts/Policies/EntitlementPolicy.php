<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Policies;

use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\Orders\Contracts\CustomerOrders;
use Illuminate\Contracts\Auth\Authenticatable;

/** A customer may use only the downloads of their own orders. (A guest is decided by the order link and the phone code, not here.) */
final class EntitlementPolicy
{
    public function __construct(private readonly CustomerOrders $orders) {}

    public function download(Authenticatable $user, Entitlement $entitlement): bool
    {
        return $this->orders->owns((int) $user->getAuthIdentifier(), $entitlement->order_id);
    }
}
