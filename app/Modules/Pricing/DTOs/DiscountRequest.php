<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

final readonly class DiscountRequest
{
    /**
     * @param  list<DiscountLine>  $lines
     * @param  list<string>  $couponCodes  as typed; at most two are considered
     * @param  string|null  $redeemer  "u:<id>" / "p:<hash>"; null while unknown (the per-customer limit is then only enforced at order time)
     */
    public function __construct(public array $lines, public array $couponCodes = [], public ?string $redeemer = null, public string $locale = 'en') {}
}
