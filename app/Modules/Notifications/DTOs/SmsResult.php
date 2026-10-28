<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

final readonly class SmsResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerReference = null,
        public ?string $error = null,
    ) {}
}
