<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\DTOs\AddressView;

/** A saved address as the app sees it: names in the asked language, the reference ids to send back, no internal id. */
final class AddressResource
{
    public const TYPE = 'array{id: string, label: string|null, recipient: string, phone: string, division: array{id: int, name: string}, district: array{id: int, name: string}, upazila: array{id: int, name: string}, area: string|null, address_line: string, landmark: string|null, delivery_note: string|null, postal_code: string|null, is_default: bool, one_line: string}';

    /** @return array<string, mixed> */
    public static function make(AddressView $a, string $locale): array
    {
        $name = fn (array $names): string => $names[$locale] ?? $names['en'];

        return [
            'id' => $a->publicId,
            'label' => $a->label,
            'recipient' => $a->recipient,
            'phone' => $a->phone,
            'division' => ['id' => $a->divisionId, 'name' => $name($a->division)],
            'district' => ['id' => $a->districtId, 'name' => $name($a->district)],
            'upazila' => ['id' => $a->upazilaId, 'name' => $name($a->upazila)],
            'area' => $a->area,
            'address_line' => $a->addressLine,
            'landmark' => $a->landmark,
            'delivery_note' => $a->deliveryNote,
            'postal_code' => $a->postalCode,
            'is_default' => $a->isDefault,
            'one_line' => $a->oneLine($locale),
        ];
    }
}
