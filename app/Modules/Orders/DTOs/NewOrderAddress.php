<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

final readonly class NewOrderAddress
{
    /**
     * @param  'shipping'|'billing'  $type
     * @param  array<string, array<string, string>>  $location  level (division|district|upazila) => locale => name
     */
    public function __construct(
        public string $type,
        public string $recipientName,
        public string $phone,
        public ?int $divisionId,
        public ?int $districtId,
        public ?int $upazilaId,
        public array $location,
        public string $addressLine,
        public ?string $postalCode = null,
        public ?string $area = null,
        public ?string $landmark = null,
    ) {}
}
