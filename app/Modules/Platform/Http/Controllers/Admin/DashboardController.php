<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Contracts\KeyFigureTile;
use App\Modules\Platform\DTOs\TileFigure;
use App\Modules\Platform\Support\DashboardRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The staff home page. Role-aware: a person sees only the tiles whose permissions they hold. A tile that cannot be worked out shows as unavailable; it never takes the page down.
 * Layout: a greeting and the date, shortcuts to what the person may do, then "Today" (headline figures with their change since yesterday) and "Needs attention" (work queues).
 */
class DashboardController
{
    /** Shortcuts: label key, route, permission the person must hold. Only the ones they may use are shown. */
    private const SHORTCUTS = [
        ['platform::productivity.dashboard.go_orders', 'admin.orders.index', 'orders.view'],
        ['platform::productivity.dashboard.go_new_product', 'admin.catalog.products.create', 'catalog.create'],
        ['platform::productivity.dashboard.go_new_coupon', 'admin.promotions.coupons.create', 'promotions.manage'],
        ['platform::productivity.dashboard.go_stock', 'admin.inventory.index', 'inventory.view'],
        ['platform::productivity.dashboard.go_tickets', 'admin.support.index', 'support.view'],
        ['platform::productivity.dashboard.go_reports', 'admin.reports.index', 'reports.view'],
    ];

    public function __invoke(Request $request, DashboardRegistry $registry): View
    {
        $user = $request->user('admin');
        $today = [];
        $attention = [];
        foreach ($registry->visibleFor($user) as $tile) {
            try {
                $figure = $tile->figure();
            } catch (Throwable $e) {
                Log::warning('dashboard.tile_failed', ['tile' => $tile->code(), 'kind' => $e::class]);
                $figure = null;
            }
            $card = ['label' => $tile->label(), 'figure' => $figure, 'code' => $tile->code(), 'change' => $figure === null ? null : $this->change($figure)];
            $tile instanceof KeyFigureTile ? $today[] = $card : $attention[] = $card;
        }

        $now = CarbonImmutable::now((string) config('app.display_timezone'));
        $part = match (true) {
            $now->hour < 12 => 'morning',
            $now->hour < 17 => 'afternoon',
            default => 'evening',
        };

        return view('platform::admin.dashboard', [
            'greetingPart' => $part,
            'firstName' => explode(' ', trim((string) ($user->name ?? '')))[0],
            'now' => $now,
            'shortcuts' => array_values(array_filter(self::SHORTCUTS, fn (array $s): bool => $user !== null && $user->can($s[2]) === true)),
            'tiles' => [...$today, ...$attention],   // every tile, in registration order (the page shows them in two sections)
            'today' => $today,
            'attention' => $attention,
            'attentionTotal' => array_sum(array_map(fn (array $c): int => $c['figure'] !== null && $c['figure']->kind === 'count' ? $c['figure']->value : 0, $attention)),
        ]);
    }

    /** @return array{direction: string, percent: int}|null the change since yesterday, when there is a yesterday to compare with */
    private function change(TileFigure $figure): ?array
    {
        if ($figure->before === null) {
            return null;
        }
        $diff = $figure->value - $figure->before;
        if ($diff === 0) {
            return ['direction' => 'same', 'percent' => 0];
        }

        // from nothing to something has no meaningful percentage: say "new" (percent -1) instead of dividing by zero
        return ['direction' => $diff > 0 ? 'up' : 'down', 'percent' => $figure->before === 0 ? -1 : (int) round(abs($diff) / abs($figure->before) * 100)];
    }
}
