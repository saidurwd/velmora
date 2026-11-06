<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The per-product star counts. `add` and `remove` are called only inside the transaction that publishes or removes a published review, so the numbers always match the
 * reviews. Removing a star that was never counted is a bug and stops the transaction, instead of quietly drifting.
 */
final class RatingStats
{
    public function __construct(private readonly Clock $clock) {}

    public function add(int $productId, int $rating): void
    {
        $column = $this->column($rating);
        $now = $this->clock->now();
        DB::table('product_rating_stats')->insertOrIgnore(['product_id' => $productId, 'updated_at' => $now]);
        $plusOne = match ($rating) {
            1 => '`stars_1` + 1', 2 => '`stars_2` + 1', 3 => '`stars_3` + 1', 4 => '`stars_4` + 1', default => '`stars_5` + 1',
        };
        DB::table('product_rating_stats')->where('product_id', $productId)->update([$column => DB::raw($plusOne), 'updated_at' => $now]);
    }

    public function remove(int $productId, int $rating): void
    {
        $column = $this->column($rating);
        $minusOne = match ($rating) {
            1 => '`stars_1` - 1', 2 => '`stars_2` - 1', 3 => '`stars_3` - 1', 4 => '`stars_4` - 1', default => '`stars_5` - 1',
        };
        $changed = DB::table('product_rating_stats')->where('product_id', $productId)->where($column, '>', 0)->update([$column => DB::raw($minusOne), 'updated_at' => $this->clock->now()]);
        if ($changed !== 1) {
            throw new LogicException('A star that was never counted cannot be removed.');
        }
    }

    /** @return array{count: int, average: float, stars: array<int, int>} */
    public function forProduct(int $productId): array
    {
        $row = DB::table('product_rating_stats')->where('product_id', $productId)->first();   // primary key
        $stars = [1 => (int) ($row->stars_1 ?? 0), 2 => (int) ($row->stars_2 ?? 0), 3 => (int) ($row->stars_3 ?? 0), 4 => (int) ($row->stars_4 ?? 0), 5 => (int) ($row->stars_5 ?? 0)];
        $count = array_sum($stars);

        return ['count' => $count, 'average' => $count === 0 ? 0.0 : (float) (array_sum(array_map(fn (int $star, int $n): int => $star * $n, array_keys($stars), $stars)) / $count), 'stars' => $stars];
    }

    private function column(int $rating): string
    {
        if ($rating < 1 || $rating > 5) {
            throw new LogicException('A rating is 1 to 5.');
        }

        return "stars_{$rating}";
    }
}
