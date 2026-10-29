<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Unpaid => [self::Pending, self::Paid, self::Failed],
            self::Pending => [self::Paid, self::Failed, self::Unpaid],
            self::Failed => [self::Pending, self::Unpaid],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Money has been taken (and not fully given back). */
    public function holdsMoney(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }
}
