<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Fmt;

/** The one way money leaves the API (API_ARCHITECTURE §1): integer minor units, the currency, and a ready-to-show string in the request's language. */
final class MoneyData
{
    /** @return array{amount_minor: int, currency: string, formatted: string} */
    public static function from(Money $money): array
    {
        return ['amount_minor' => $money->amountMinor, 'currency' => $money->currency, 'formatted' => Fmt::money($money)];
    }
}
