<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\ApiOrderAccess;
use App\Modules\Orders\DTOs\OrderAccess;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Support\SignedToken;
use Illuminate\Http\Request;

final class ApiOrderAccessResolver implements ApiOrderAccess
{
    private const PURPOSE = 'order-access';

    public function __construct(private readonly SignedToken $tokens) {}

    public function find(Request $request, string $orderPublicId): ?OrderAccess
    {
        if (preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $orderPublicId) !== 1) {
            return null;
        }

        $order = Order::query()->where('public_id', $orderPublicId)->first(['id', 'public_id', 'user_id']);  // unique public_id
        if ($order === null) {
            return null;
        }

        $userId = $request->user()?->getAuthIdentifier();
        if ($userId !== null && $order->user_id !== null && (int) $userId === $order->user_id) {
            return new OrderAccess($order->id, $order->public_id, $order->user_id, true);
        }

        $claims = $this->tokens->read(self::PURPOSE, $request->headers->get(self::HEADER));
        if ($claims !== null && hash_equals($order->public_id, (string) ($claims['o'] ?? ''))) {
            return new OrderAccess($order->id, $order->public_id, $order->user_id, false);
        }

        return null;
    }

    public function issueToken(string $orderPublicId): string
    {
        return $this->tokens->issue(self::PURPOSE, ['o' => $orderPublicId], max(1, (int) config('orders.confirmation_link_days')) * 86400);
    }
}
