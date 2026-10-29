<?php

declare(strict_types=1);

namespace App\Modules\Cart\Listeners;

use App\Modules\Cart\Actions\MergeGuestCart;
use App\Modules\Cart\Services\GuestCartToken;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/** When a customer signs in, the cart they built as a guest joins their own. */
final class MergeGuestCartOnLogin
{
    public function __construct(private readonly Request $request, private readonly GuestCartToken $tokens, private readonly MergeGuestCart $merge) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $token = $this->tokens->fromRequest($this->request);
        if ($token === null) {
            return;
        }

        $this->merge->handle((int) $event->user->getAuthIdentifier(), $token);
        $this->tokens->forget();
    }
}
