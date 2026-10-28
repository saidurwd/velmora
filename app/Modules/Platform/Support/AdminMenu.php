<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/** Sidebar registry: modules add entries; each viewer only sees entries they hold the permission for. */
final class AdminMenu
{
    /** @var list<array{label: string, route: string, permission: ?string, order: int}> */
    private array $items = [];

    public function add(string $labelKey, string $route, ?string $permission, int $order = 100): void
    {
        $this->items[] = ['label' => $labelKey, 'route' => $route, 'permission' => $permission, 'order' => $order];
    }

    /** @return list<array{label: string, route: string, permission: ?string, order: int}> */
    public function visibleFor(?Authenticatable $user): array
    {
        $items = array_values(array_filter(
            $this->items,
            fn (array $item) => $item['permission'] === null || ($user !== null && method_exists($user, 'can') && $user->can($item['permission'])),
        ));

        usort($items, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $items;
    }
}
