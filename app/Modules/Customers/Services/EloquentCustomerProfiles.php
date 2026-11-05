<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\DTOs\CustomerProfile;
use App\Modules\Customers\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

final class EloquentCustomerProfiles implements CustomerProfiles
{
    public function findByUserId(int $userId): ?CustomerProfile
    {
        $customer = Customer::query()->where('user_id', $userId)->first();

        return $customer ? $this->dto($customer) : null;
    }

    public function findManyByUserIds(array $userIds): array
    {
        $out = [];
        foreach (Customer::query()->whereIn('user_id', $userIds)->get() as $customer) {   // unique user_id
            $out[(int) $customer->user_id] = $this->dto($customer);
        }

        return $out;
    }

    public function ensureForUser(int $userId, ?string $name, ?string $email, ?string $phone): CustomerProfile
    {
        $existing = Customer::query()->where('user_id', $userId)->first();
        if ($existing !== null) {
            return $this->dto($existing);
        }

        try {
            $customer = (new Customer)->forceFill(['user_id' => $userId, 'name' => $name, 'email' => $email, 'phone' => $phone]);
            $customer->save();
        } catch (UniqueConstraintViolationException) {
            // a concurrent delivery created it first (customers.user_id is unique): that is the idempotent outcome
            $customer = Customer::query()->where('user_id', $userId)->firstOrFail();
        }

        return $this->dto($customer);
    }

    public function updateName(int $userId, string $name): void
    {
        Customer::query()->where('user_id', $userId)->update(['name' => $name]);
    }

    public function updateProfile(int $userId, string $name, ?string $birthDate): void
    {
        $customer = Customer::query()->where('user_id', $userId)->firstOrFail();

        $customer->name = $name;
        $customer->birth_date = $birthDate === null ? null : CarbonImmutable::parse($birthDate);

        $customer->save();
    }

    private function dto(Customer $c): CustomerProfile
    {
        return new CustomerProfile($c->public_id, $c->name, $c->email, $c->phone, $c->birth_date?->toDateString());
    }
}
