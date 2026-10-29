<?php

declare(strict_types=1);

namespace App\Modules\Customers\DTOs;

/** What a person types into the address form (checkout and address book). Cleaned and validated by SaveAddress / AddressValidator. */
final readonly class AddressInput
{
    public function __construct(
        public string $recipient,
        public string $phone,
        public int $divisionId,
        public int $districtId,
        public int $upazilaId,
        public string $addressLine,
        public ?string $area = null,
        public ?string $landmark = null,
        public ?string $deliveryNote = null,
        public ?string $postalCode = null,
        public ?string $label = null,
        public bool $makeDefault = false,
    ) {}

    /** @param  array<string, mixed>  $v  validated form input */
    public static function fromArray(array $v): self
    {
        $s = fn (string $k): ?string => ($v[$k] ?? '') === '' ? null : (string) $v[$k];

        return new self(
            (string) ($v['recipient'] ?? ''), (string) ($v['phone'] ?? ''),
            (int) ($v['division_id'] ?? 0), (int) ($v['district_id'] ?? 0), (int) ($v['upazila_id'] ?? 0),
            (string) ($v['address_line'] ?? ''), $s('area'), $s('landmark'), $s('delivery_note'), $s('postal_code'), $s('label'), ! empty($v['make_default']),
        );
    }
}
