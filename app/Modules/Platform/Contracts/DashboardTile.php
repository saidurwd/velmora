<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use App\Modules\Platform\DTOs\TileFigure;

/** One figure on the staff dashboard. Each module registers its own with the DashboardRegistry; Platform never reads their tables. */
interface DashboardTile
{
    /** A short unique code. */
    public function code(): string;

    /** The translation key of the tile's title. */
    public function label(): string;

    /**
     * Every permission the person must hold to see this tile.
     *
     * @return list<string>
     */
    public function permissions(): array;

    /** The figure, worked out when the dashboard is opened. The link must open the list the figure counts. */
    public function figure(): TileFigure;
}
