<?php

declare(strict_types=1);

namespace App\Modules\Customers\DTOs;

/** A stored address as other modules see it. Names are resolved in both languages so an order can snapshot them. */
final readonly class AddressView
{
    /**
     * @param  array{en: string, bn: string}  $division
     * @param  array{en: string, bn: string}  $district
     * @param  array{en: string, bn: string}  $upazila
     */
    public function __construct(
        public string $publicId,
        public ?string $label,
        public string $recipient,
        public string $phone,
        public int $divisionId,
        public int $districtId,
        public int $upazilaId,
        public array $division,
        public array $district,
        public array $upazila,
        public ?string $area,
        public string $addressLine,
        public ?string $landmark,
        public ?string $deliveryNote,
        public ?string $postalCode,
        public bool $isDefault,
    ) {}

    /** One readable line in the given language: "House 5, Road 3, Mirpur, Mirpur, Dhaka, Dhaka". */
    public function oneLine(string $locale): string
    {
        return implode(', ', array_filter([$this->addressLine, $this->area, $this->upazila[$locale] ?? $this->upazila['en'], $this->district[$locale] ?? $this->district['en'], $this->division[$locale] ?? $this->division['en'], $this->postalCode]));
    }
}
