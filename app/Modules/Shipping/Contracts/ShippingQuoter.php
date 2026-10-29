<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\DTOs\ShippingQuote;
use App\Modules\Shipping\DTOs\ShippingRequest;

interface ShippingQuoter
{
    public function quote(ShippingRequest $request, string $locale): ShippingQuote;
}
