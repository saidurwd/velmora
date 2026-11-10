<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** Why money is given back, as a code the reports can group by. The staff member's own words stay in `refunds.reason`. */
enum RefundReason: string
{
    case CustomerChangedMind = 'customer_changed_mind';
    case ItemReturned = 'item_returned';
    case ItemDamaged = 'item_damaged';
    case WrongItem = 'wrong_item';
    case NotDelivered = 'not_delivered';
    case PriceAdjustment = 'price_adjustment';
    case DuplicatePayment = 'duplicate_payment';
    case FraudOrRisk = 'fraud_or_risk';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return __('payments::admin.refund_reasons.'.$this->value);
    }
}
