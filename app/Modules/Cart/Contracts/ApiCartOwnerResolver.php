<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartOwner;
use Illuminate\Http\Request;

/** Whose cart an APP request is about: the signed-in customer's, or the guest holding the `X-Cart-Token` header (the website reads a cookie instead). Reading never creates a cart. */
interface ApiCartOwnerResolver
{
    public function fromRequest(Request $request): CartOwner;

    /** Like `fromRequest`, but a guest with no token yet is given a new one (the caller returns it to the app). */
    public function forWrite(Request $request): CartOwner;
}
