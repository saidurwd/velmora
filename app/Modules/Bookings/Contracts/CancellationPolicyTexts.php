<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Contracts;

/** The cancellation words a customer is shown before they book a service, in their language. They come from the shop's policy records. */
interface CancellationPolicyTexts
{
    /**
     * @param  list<string>  $skus
     * @return list<array{title: string, text: string}> each different policy once, for the service products among these SKUs; empty when none apply
     */
    public function forSkus(array $skus, string $locale): array;
}
