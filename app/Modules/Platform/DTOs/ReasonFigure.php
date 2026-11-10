<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

/** How many times a reason code was used in a period and what it came to (an order's value or a refund's amount). A null code means "not recorded" (made before codes existed). */
final readonly class ReasonFigure
{
    public function __construct(public ?string $code, public string $currency, public int $count, public int $amountMinor) {}
}
