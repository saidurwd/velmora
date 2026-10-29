<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** What the cash-on-delivery rules say about one order that is about to be placed. */
final readonly class RiskAssessment
{
    /** @param  list<array{rule: string, score: int, details: array<string, scalar|null>}>  $flags  reasons to have a person look at the order */
    public function __construct(
        public bool $blocked = false,
        public bool $tooManyOpenCod = false,
        public int $maxOpenCod = 0,
        public bool $needsPhoneConfirmation = false,
        public array $flags = [],
    ) {}

    public function needsReview(): bool
    {
        return $this->flags !== [];
    }
}
