<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

/** The money of one day for one group of orders (channel, payment method, language, currency), while a day is being worked out. */
final class MoneyGroup
{
    public int $collectedMinor = 0;

    public int $collectedCount = 0;

    public int $refundedMinor = 0;

    public int $refundedLiveMinor = 0;

    public int $refundCount = 0;

    public function __construct(public readonly string $channel, public readonly string $paymentMethod, public readonly string $locale, public readonly string $currency) {}

    /** @return array<string, int|string> */
    public function row(): array
    {
        return [
            'channel' => $this->channel, 'payment_method' => $this->paymentMethod, 'locale' => $this->locale, 'currency' => $this->currency, 'collected_minor' => $this->collectedMinor,
            'collected_count' => $this->collectedCount, 'refunded_minor' => $this->refundedMinor, 'refunded_live_minor' => $this->refundedLiveMinor, 'refund_count' => $this->refundCount,
        ];
    }
}
