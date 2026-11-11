<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Dashboard;

use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\DTOs\TileFigure;
use Illuminate\Support\Facades\DB;

final class ReviewsWaitingTile implements DashboardTile
{
    public function code(): string
    {
        return 'reviews_waiting';
    }

    public function label(): string
    {
        return 'engagement::dashboard.reviews_waiting';
    }

    public function permissions(): array
    {
        return ['reviews.moderate'];
    }

    public function figure(): TileFigure
    {
        return new TileFigure(DB::table('reviews')->where('status', 'pending')->count(), 'count', 'admin.reviews.index', ['status' => 'pending']);   // index (status, id)
    }
}
