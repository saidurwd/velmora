<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Actions\DeleteAddress;
use App\Modules\Customers\Actions\SaveAddress;
use App\Modules\Customers\Actions\SetDefaultAddress;
use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\Contracts\Geography;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Customers\DTOs\AddressView;
use App\Modules\Customers\Exceptions\AddressException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;

final class EloquentAddressBook implements AddressBook
{
    public function __construct(
        private readonly CustomerProfiles $profiles,
        private readonly Geography $geography,
        private readonly SaveAddress $save,
        private readonly DeleteAddress $delete,
        private readonly SetDefaultAddress $setDefault,
    ) {}

    public function list(int $userId): array
    {
        $customerId = $this->customerId($userId);
        $rows = CustomerAddress::query()->where('customer_id', $customerId)->orderByDesc('is_default')->orderByDesc('id')->get();

        return $this->views(array_values($rows->all()));
    }

    public function find(int $userId, string $publicId): ?AddressView
    {
        $row = $this->owned($userId, $publicId);

        return $row === null ? null : $this->views([$row])[0];
    }

    public function save(int $userId, ?string $publicId, AddressInput $input): AddressView
    {
        $existing = null;
        if ($publicId !== null) {
            $existing = $this->owned($userId, $publicId) ?? throw AddressException::notFound();
        }

        $saved = $this->save->handle($this->customerId($userId), $existing, $input);

        return $this->views([$saved])[0];
    }

    public function delete(int $userId, string $publicId): void
    {
        $this->delete->handle($this->owned($userId, $publicId) ?? throw AddressException::notFound());
    }

    public function makeDefault(int $userId, string $publicId): void
    {
        $this->setDefault->handle($this->owned($userId, $publicId) ?? throw AddressException::notFound());
    }

    private function customerId(int $userId): int
    {
        $this->profiles->ensureForUser($userId, null, null, null);

        return (int) Customer::query()->where('user_id', $userId)->value('id');
    }

    private function owned(int $userId, string $publicId): ?CustomerAddress
    {
        return CustomerAddress::query()->where('customer_id', $this->customerId($userId))->where('public_id', $publicId)->first();
    }

    /**
     * @param  list<CustomerAddress>  $rows
     * @return list<AddressView>
     */
    private function views(array $rows): array
    {
        $out = [];
        foreach ($rows as $a) {
            $names = $this->geography->resolve($a->division_id, $a->district_id, $a->upazila_id) ?? ['division' => ['en' => '', 'bn' => ''], 'district' => ['en' => '', 'bn' => ''], 'upazila' => ['en' => '', 'bn' => '']];
            $out[] = new AddressView($a->public_id, $a->label, $a->recipient, $a->phone, $a->division_id, $a->district_id, $a->upazila_id, $names['division'], $names['district'], $names['upazila'], $a->area, $a->address_line, $a->landmark, $a->delivery_note, $a->postal_code, $a->is_default);
        }

        return $out;
    }
}
