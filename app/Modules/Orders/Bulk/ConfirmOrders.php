<?php

declare(strict_types=1);

namespace App\Modules\Orders\Bulk;

use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Platform\Contracts\BulkAction;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Confirms the chosen orders, each through the same Action as the order page's own "confirm" button (so a flagged order, or one that cannot be confirmed, is refused row by row). */
final class ConfirmOrders implements BulkAction
{
    public function __construct(private readonly TransitionOrder $transition) {}

    public function code(): string
    {
        return 'orders.confirm';
    }

    public function screen(): string
    {
        return 'orders';
    }

    public function label(): string
    {
        return 'orders::bulk.confirm';
    }

    public function permissions(): array
    {
        return ['orders.update'];
    }

    public function fields(Authenticatable $by): array
    {
        return [];
    }

    public function describe(array $ids): array
    {
        // unique public_id: the chosen rows
        return DB::table('orders')->whereIn('public_id', $ids)->pluck('order_number', 'public_id')->map(fn ($n): string => (string) $n)->all();
    }

    public function run(Actor $actor, Authenticatable $by, string $id, array $params): void
    {
        $orderId = DB::table('orders')->where('public_id', $id)->value('id');   // unique public_id
        if ($orderId === null) {
            throw new OrderException('not_found');
        }

        $this->transition->status($actor, (int) $orderId, OrderStatus::Confirmed);
    }

    public function explain(Throwable $e): string
    {
        return $e instanceof OrderException && \Lang::has('orders::orders.errors.'.$e->reason) ? __('orders::orders.errors.'.$e->reason, $e->params) : __('platform::bulk.unknown');
    }
}
