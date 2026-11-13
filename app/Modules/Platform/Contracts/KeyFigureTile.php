<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/** A dashboard tile that is a headline figure for the day (shown large, under "Today", with its change since yesterday), not a work queue. Add it to a tile that has a `before` and a `series`. */
interface KeyFigureTile extends DashboardTile {}
