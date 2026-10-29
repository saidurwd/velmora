<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\Geography;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Customers\Exceptions\AddressException;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Exceptions\InvalidPhoneNumber;
use App\Modules\Platform\Support\BanglaText;

/** The checks every address goes through, whether it is saved to an address book or used once at checkout. */
final class AddressValidator
{
    public function __construct(private readonly Geography $geography) {}

    /**
     * @return array{phone: string, postal: string|null, names: array{division: array{en: string, bn: string}, district: array{en: string, bn: string}, upazila: array{en: string, bn: string}}}
     *
     * @throws AddressException
     */
    public function validate(AddressInput $in): array
    {
        try {
            $phone = PhoneNumber::parse($in->phone)->e164();
        } catch (InvalidPhoneNumber) {
            throw AddressException::phoneInvalid();
        }

        $names = $this->geography->resolve($in->divisionId, $in->districtId, $in->upazilaId)
            ?? throw AddressException::locationMismatch(); // the three ids must form a real chain, whatever the browser sent

        $postal = $in->postalCode === null ? null : BanglaText::numeric($in->postalCode);
        if ($postal !== null && preg_match('/^\d{4}$/', $postal) !== 1) {
            throw AddressException::postalInvalid();
        }

        return ['phone' => $phone, 'postal' => $postal, 'names' => $names];
    }
}
