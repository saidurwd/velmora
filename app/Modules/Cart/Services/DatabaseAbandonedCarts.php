<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Contracts\AbandonedCarts;
use App\Modules\Cart\DTOs\AbandonedCart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseAbandonedCarts implements AbandonedCarts
{
    public function idle(CarbonImmutable $idleSince, CarbonImmutable $notBefore, int $limit, int $offset = 0): array
    {
        // Unique carts.user_id, then the lines of each cart through the unique (cart_id, variant_id) index. Carts are purged after 90 days, so the scan stays small.
        $rows = DB::table('carts')
            ->join('cart_items', 'cart_items.cart_id', '=', 'carts.id')
            ->whereNotNull('carts.user_id')
            ->groupBy('carts.id', 'carts.user_id')
            ->havingRaw('MAX(cart_items.updated_at) <= ?', [$idleSince->utc()->format('Y-m-d H:i:s.u')])
            ->havingRaw('MAX(cart_items.updated_at) > ?', [$notBefore->utc()->format('Y-m-d H:i:s.u')])
            ->orderByRaw('MAX(cart_items.updated_at)')
            ->orderBy('carts.id')
            ->limit($limit)
            ->offset($offset)
            ->get(['carts.user_id', DB::raw('MAX(cart_items.updated_at) AS changed_at')]);

        return array_values($rows->map(fn ($r): AbandonedCart => new AbandonedCart((int) $r->user_id, CarbonImmutable::parse((string) $r->changed_at, 'UTC')))->all());
    }
}
