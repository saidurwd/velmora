<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Contracts\ApiCartOwnerResolver;
use App\Modules\Cart\DTOs\CartOwner;
use Illuminate\Http\Request;

/**
 * Whose cart an app request is about: the signed-in customer's own, or the guest holding the `X-Cart-Token` (random, 160 bits, only its hash is stored —
 * exactly the website's cookie, sent as a header). A malformed token counts as none. Reading never creates a cart; the first write hands out a token.
 */
final class ApiCartOwners implements ApiCartOwnerResolver
{
    public const HEADER = 'X-Cart-Token';

    public function fromRequest(Request $request): CartOwner
    {
        $user = $request->user();

        return $user !== null ? CartOwner::user((int) $user->getAuthIdentifier()) : CartOwner::guest($this->token($request));
    }

    /** Like `fromRequest`, but a guest with no token yet is given one, to be returned to the app. */
    public function forWrite(Request $request): CartOwner
    {
        $owner = $this->fromRequest($request);

        return $owner->isKnown() ? $owner : CartOwner::guest(bin2hex(random_bytes(20)));
    }

    public function token(Request $request): ?string
    {
        $token = $request->headers->get(self::HEADER);

        return is_string($token) && preg_match('/^[a-f0-9]{40}$/', $token) === 1 ? $token : null;
    }
}
