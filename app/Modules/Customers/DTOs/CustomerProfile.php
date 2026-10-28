<?php

declare(strict_types=1);

namespace App\Modules\Customers\DTOs;

final readonly class CustomerProfile
{
    public function __construct(
        public string $publicId,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public bool $marketingConsent,
        public ?string $birthDate = null,
    ) {}
}
