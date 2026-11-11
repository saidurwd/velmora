<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Support\DashboardRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Role-aware: a person sees only the tiles whose permissions they hold. A tile that cannot be worked out shows as unavailable; it never takes the page down. */
class DashboardController
{
    public function __invoke(Request $request, DashboardRegistry $registry): View
    {
        $tiles = [];
        foreach ($registry->visibleFor($request->user('admin')) as $tile) {
            try {
                $tiles[] = ['label' => $tile->label(), 'figure' => $tile->figure(), 'code' => $tile->code()];
            } catch (Throwable $e) {
                Log::warning('dashboard.tile_failed', ['tile' => $tile->code(), 'kind' => $e::class]);
                $tiles[] = ['label' => $tile->label(), 'figure' => null, 'code' => $tile->code()];
            }
        }

        return view('platform::admin.dashboard', ['tiles' => $tiles]);
    }
}
