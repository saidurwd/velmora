<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Orders\Contracts\CodOrders;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where the cash-on-delivery money is stuck. "Uncollected": orders past confirmation whose cash has not been recorded. "Unremitted": cash recorded
 * as collected that no courier statement has covered yet. Both are counted by how many days old they are.
 */
final class CodAging
{
    public const BUCKETS = ['0-3', '4-7', '8-14', '15+'];

    public function __construct(private readonly CodOrders $orders, private readonly Clock $clock) {}

    /** @return array{uncollected: array<string, array{count: int, minor: int}>, unremitted: array<string, array{count: int, minor: int}>} */
    public function report(): array
    {
        $now = $this->clock->now();
        $uncollected = $this->empty();
        foreach ($this->orders->awaitingCash() as $order) {
            $bucket = $this->bucket($order->placedAt, $now);
            $uncollected[$bucket] = $this->add($uncollected[$bucket], $order->amountMinor);
        }

        $unremitted = $this->empty();
        // serves the unremitted view: collections no statement item points at (index on collected_at; the NOT EXISTS uses the UNIQUE on cod_collection_id)
        $rows = DB::table('cod_collections as c')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cod_remittance_items as i')->whereColumn('i.cod_collection_id', 'c.id'))
            ->get(['c.amount_minor', 'c.collected_at']);
        foreach ($rows as $row) {
            $bucket = $this->bucket(CarbonImmutable::parse((string) $row->collected_at, 'UTC'), $now);
            $unremitted[$bucket] = $this->add($unremitted[$bucket], (int) $row->amount_minor);
        }

        return ['uncollected' => $uncollected, 'unremitted' => $unremitted];
    }

    /**
     * @param  array{count: int, minor: int}  $bucket
     * @return array{count: int, minor: int}
     */
    private function add(array $bucket, int $minor): array
    {
        return ['count' => $bucket['count'] + 1, 'minor' => $bucket['minor'] + $minor];
    }

    /** @return array<string, array{count: int, minor: int}> */
    private function empty(): array
    {
        $empty = [];
        foreach (self::BUCKETS as $bucket) {
            $empty[$bucket] = ['count' => 0, 'minor' => 0];
        }

        return $empty;
    }

    private function bucket(CarbonImmutable $since, CarbonImmutable $now): string
    {
        $days = (int) floor($since->diffInSeconds($now, false) / 86400);

        return match (true) {
            $days <= 3 => '0-3',
            $days <= 7 => '4-7',
            $days <= 14 => '8-14',
            default => '15+',
        };
    }
}
