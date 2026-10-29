<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Customers\DTOs\AddressView;
use App\Modules\Customers\Exceptions\AddressException;

/** A signed-in customer's saved addresses. Everything is scoped by the user id: one customer can never reach another's address. */
interface AddressBook
{
    /** @return list<AddressView> default address first */
    public function list(int $userId): array;

    public function find(int $userId, string $publicId): ?AddressView;

    /** @throws AddressException */
    public function save(int $userId, ?string $publicId, AddressInput $input): AddressView;

    /** @throws AddressException */
    public function delete(int $userId, string $publicId): void;

    /** @throws AddressException */
    public function makeDefault(int $userId, string $publicId): void;
}
