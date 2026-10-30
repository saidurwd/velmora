<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Exceptions\MoneyException;

/** Providers speak decimal strings ("55.40"); we speak paisa. The conversion happens here, with string arithmetic, and is exact or refused. */
final class ProviderAmount
{
    /** @throws PaymentException when the text is not an exact amount */
    public static function toMoney(string $decimal, string $currency = 'BDT'): Money
    {
        $decimal = trim($decimal);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $decimal) !== 1) {
            throw new PaymentException('provider_amount_invalid');
        }

        try {
            return Money::fromMajorString($decimal, $currency);
        } catch (MoneyException) {
            throw new PaymentException('provider_amount_invalid');
        }
    }

    /** "55.40": always two decimals, as the providers expect. */
    public static function toDecimal(Money $money): string
    {
        return $money->toMajorString();
    }
}
