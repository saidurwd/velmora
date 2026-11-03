<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartOwner;
use Illuminate\Http\Request;

/** Whose cart a request is about: the signed-in customer, or the guest holding the cart cookie. Reading never creates anything. */
interface CartOwnerResolver
{
    public function fromRequest(Request $request): CartOwner;

    /** Like `fromRequest`, but a first-time guest is given a cart cookie so what they put in it is theirs. */
    public function forWrite(Request $request): CartOwner;
}
