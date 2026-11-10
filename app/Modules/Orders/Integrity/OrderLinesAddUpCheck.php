<?php

declare(strict_types=1);

namespace App\Modules\Orders\Integrity;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Contracts\IntegrityCheck;
use App\Modules\Platform\DTOs\IntegrityResult;
use Illuminate\Support\Facades\DB;

/**
 * The lines of an order (price times quantity) add up to its stored subtotal. Orders and their lines are append-only, so only recent orders are
 * looked at (config ops.integrity.window_days): an old order that balanced still does.
 */
final class OrderLinesAddUpCheck implements IntegrityCheck
{
    public function __construct(private readonly Clock $clock) {}

    public function code(): string
    {
        return 'order_lines_add_up';
    }

    public function run(): IntegrityResult
    {
        $cap = (int) config('ops.integrity.max_problems');
        $since = $this->clock->now()->subDays((int) config('ops.integrity.window_days'))->format('Y-m-d H:i:s.u');

        $rows = DB::select("SELECT o.public_id AS id, o.subtotal_minor AS stored, SUM(i.unit_price_minor * i.quantity) AS line_sum
              FROM orders o JOIN order_items i ON i.order_id = o.id WHERE o.created_at >= ?
             GROUP BY o.id, o.public_id, o.subtotal_minor HAVING SUM(i.unit_price_minor * i.quantity) <> o.subtotal_minor LIMIT {$cap}", [$since]);

        return new IntegrityResult(count($rows), array_values(array_map(fn ($r) => "order {$r->id}: subtotal {$r->stored}, lines {$r->line_sum} (minor units)", array_slice($rows, 0, (int) config('ops.integrity.sample')))));
    }
}
