<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Contracts;

/** The refund words a customer is shown before they buy a digital product. They come from the shop's policy records, in the customer's language. */
interface RefundPolicyTexts
{
    /**
     * @param  list<string>  $skus
     * @return list<array{title: string, text: string}> each different policy once, for the digital products among these SKUs; empty when none apply
     */
    public function forSkus(array $skus, string $locale): array;
}
