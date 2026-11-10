<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** Why an order was cancelled, as a code the reports can group by. The words stay in the order's history and `orders.cancel_reason`. */
enum CancelReason: string
{
    case CustomerRequest = 'customer_request';
    case OutOfStock = 'out_of_stock';
    case PaymentFailed = 'payment_failed';
    case AddressProblem = 'address_problem';
    case FraudOrRisk = 'fraud_or_risk';
    case DuplicateOrder = 'duplicate_order';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return __('orders::orders.cancel_reasons.'.$this->value);
    }
}
