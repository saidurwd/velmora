<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Contracts\Geography;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Customers\Exceptions\AddressException;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Exceptions\InvalidPhoneNumber;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;

/** Creates or edits one address of one customer. The first address, or one marked "default", becomes the default. */
final class SaveAddress
{
    public const MAX_PER_CUSTOMER = 10;

    public function __construct(private readonly Geography $geography) {}

    /** @throws AddressException */
    public function handle(int $customerId, ?CustomerAddress $address, AddressInput $in): CustomerAddress
    {
        try {
            $phone = PhoneNumber::parse($in->phone)->e164();
        } catch (InvalidPhoneNumber) {
            throw AddressException::phoneInvalid();
        }

        if ($this->geography->resolve($in->divisionId, $in->districtId, $in->upazilaId) === null) {
            throw AddressException::locationMismatch(); // the three ids must form a real chain, whatever the browser sent
        }

        $postal = $in->postalCode === null ? null : BanglaText::numeric($in->postalCode);
        if ($postal !== null && preg_match('/^\d{4}$/', $postal) !== 1) {
            throw AddressException::postalInvalid();
        }

        return Transactions::run(function () use ($customerId, $address, $in, $phone, $postal): CustomerAddress {
            // serialise this customer's address changes (two tabs saving at once must not both become "default")
            $count = CustomerAddress::query()->where('customer_id', $customerId)->lockForUpdate()->count();
            if ($address === null && $count >= self::MAX_PER_CUSTOMER) {
                throw AddressException::limitReached();
            }

            $makeDefault = $in->makeDefault || ($address === null && $count === 0);
            if ($makeDefault) {
                CustomerAddress::query()->where('customer_id', $customerId)->where('is_default', true)->update(['is_default' => false]);
            }

            $address ??= new CustomerAddress(['customer_id' => $customerId]);
            $address->forceFill([
                'label' => $this->clean($in->label, 40),
                'recipient' => (string) $this->clean($in->recipient, 120),
                'phone' => $phone,
                'division_id' => $in->divisionId, 'district_id' => $in->districtId, 'upazila_id' => $in->upazilaId,
                'area' => $this->clean($in->area, 120),
                'address_line' => (string) $this->clean($in->addressLine, 255),
                'landmark' => $this->clean($in->landmark, 160),
                'delivery_note' => $this->clean($in->deliveryNote, 255),
                'postal_code' => $postal,
                'is_default' => $makeDefault ? true : (bool) $address->is_default,
            ])->save();

            return $address;
        });
    }

    private function clean(?string $text, int $max): ?string
    {
        $text = $text === null ? '' : BanglaText::input($text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
