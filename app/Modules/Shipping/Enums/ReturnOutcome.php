<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** What is done with goods that came back. */
enum ReturnOutcome: string
{
    case Restock = 'restock';           // fit for sale again: back on the shelf where it left from
    case Quarantine = 'quarantine';     // to be looked at again: kept apart, not for sale
    case WriteOff = 'write_off';        // not fit for sale: counted in and out again, so the ledger shows it
    case NotReceived = 'not_received';  // asked for but never came back

    public function touchesStock(): bool
    {
        return $this !== self::NotReceived;
    }
}
