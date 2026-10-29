<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Customers\DTOs\AddressView;
use App\Modules\Customers\Exceptions\AddressException;

/** Validates an address typed once (guest checkout) and resolves its place names, without saving anything. */
interface AddressChecker
{
    /** @throws AddressException  the exception message is a key under customers::addresses.errors */
    public function check(AddressInput $input): AddressView;
}
