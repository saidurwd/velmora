<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Concerns;

use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Support\ProviderAmount;
use App\Modules\Platform\Support\BanglaText;

trait ParsesMoney
{
    /**
     * "1500.50" or "১৫০০.৫০" typed in taka, to paisa, exactly (never a float).
     *
     * @throws PaymentException
     */
    private function minor(string $amount): int
    {
        try {
            return ProviderAmount::toMoney(BanglaText::digitsToAscii(trim($amount)))->amountMinor;
        } catch (PaymentException) {
            throw new PaymentException('cod_amount_invalid');
        }
    }
}
