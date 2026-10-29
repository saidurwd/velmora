<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\DTOs\CartOwner;
use Illuminate\Http\Request;

/** Works out whose cart a request is about. Reading never creates anything; writing issues the guest cookie on first use. */
final class CartOwners
{
    public function __construct(private readonly GuestCartToken $tokens) {}

    public function fromRequest(Request $request): CartOwner
    {
        $user = $request->user('web');

        return $user !== null ? CartOwner::user((int) $user->getAuthIdentifier()) : CartOwner::guest($this->tokens->fromRequest($request));
    }

    public function forWrite(Request $request): CartOwner
    {
        $owner = $this->fromRequest($request);

        return $owner->isKnown() ? $owner : CartOwner::guest($this->tokens->issue());
    }
}
