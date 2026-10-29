<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\AddressChecker;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Customers\DTOs\AddressView;
use App\Modules\Platform\Support\BanglaText;

final class ValidatingAddressChecker implements AddressChecker
{
    public function __construct(private readonly AddressValidator $validator) {}

    public function check(AddressInput $input): AddressView
    {
        ['phone' => $phone, 'postal' => $postal, 'names' => $names] = $this->validator->validate($input);
        $clean = fn (?string $t, int $max): ?string => ($t = $t === null ? '' : BanglaText::input($t)) === '' ? null : mb_substr($t, 0, $max);

        return new AddressView(
            '', null, (string) $clean($input->recipient, 120), $phone, $input->divisionId, $input->districtId, $input->upazilaId,
            $names['division'], $names['district'], $names['upazila'], $clean($input->area, 120), (string) $clean($input->addressLine, 255),
            $clean($input->landmark, 160), $clean($input->deliveryNote, 255), $postal, false,
        );
    }
}
