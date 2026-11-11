<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\DashboardTile;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;

/** The dashboard tiles the modules registered. A person gets only the tiles whose permissions they all hold. */
final class DashboardRegistry
{
    /** @var list<class-string<DashboardTile>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<DashboardTile>  $class */
    public function add(string $class): void
    {
        in_array($class, $this->classes, true) || $this->classes[] = $class;
    }

    /** @return list<DashboardTile> */
    public function visibleFor(?Authenticatable $user): array
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return [];
        }

        $tiles = array_map(fn (string $class): DashboardTile => $this->container->make($class), $this->classes);

        return array_values(array_filter($tiles, fn (DashboardTile $t): bool => collect($t->permissions())->every(fn (string $p): bool => $user->can($p) === true)));
    }
}
