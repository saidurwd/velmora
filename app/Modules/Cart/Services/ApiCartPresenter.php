<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Contracts\CartPresenter;
use App\Modules\Cart\DTOs\CartView;
use App\Modules\Cart\Http\Resources\CartResource;

final class ApiCartPresenter implements CartPresenter
{
    public function present(CartView $cart, ?string $token): array
    {
        return CartResource::make($cart, $token);
    }
}
