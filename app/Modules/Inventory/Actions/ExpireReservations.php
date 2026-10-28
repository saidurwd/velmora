<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Platform\Contracts\Clock;

/** Returns stock held by reservations whose time ran out. Safe to run concurrently and repeatedly. */
final class ExpireReservations
{
    public function __construct(private readonly Clock $clock, private readonly ReleaseReservation $release) {}

    public function handle(?int $limit = null): int
    {
        $expired = 0;
        $ids = StockReservation::query()->where('status', 'active')->whereNotNull('expires_at')
            ->where('expires_at', '<=', $this->clock->now()->format('Y-m-d H:i:s.u'))->orderBy('id')
            ->limit($limit ?? (int) config('inventory.expire_batch_size'))->pluck('id');

        foreach ($ids as $id) {
            $expired += $this->release->handle((int) $id, 'expired') ? 1 : 0;
        }

        return $expired;
    }
}
