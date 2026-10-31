<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** A way to pay that the shopper may choose right now, with its label in their language. */
final readonly class PaymentMethodOption
{
    /** @param  string  $type  cod | gateway | mfs | bank */
    public function __construct(public string $code, public string $type, public string $provider, public string $label, public ?string $description) {}

    public function isCash(): bool
    {
        return $this->type === 'cod';
    }
}
