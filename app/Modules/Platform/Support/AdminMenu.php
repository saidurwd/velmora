<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Sidebar registry: modules add entries (label, route, permission, order); each viewer only sees entries they hold the permission for.
 *
 * HOW THE MENU IS ORGANISED is decided here, once, because it is about the whole product and not about any one module: entries are placed in an area (Sales, Catalog,
 * Finance, ...) by their route name, in the order the patterns are listed below. Within one pattern the entry's own `order` decides. An entry that matches no pattern is shown
 * under "More" (never hidden), and a test fails the build so the new entry gets a proper place. An area with nothing the viewer may see does not appear.
 */
final class AdminMenu
{
    /**
     * area => route-name patterns, in display order. The dashboard stands alone above the areas.
     *
     * @var array<string, list<string>>
     */
    public const AREAS = [
        'sales' => ['admin.orders.index', 'admin.orders.cancellations.*', 'admin.shipping.returns.*', 'admin.orders.fraud.settings', 'admin.orders.fraud.blocklist'],
        'fulfilment' => ['admin.shipping.shipments.*', 'admin.shipping.issues.*', 'admin.shipping.statements.*', 'admin.shipping.zones.*', 'admin.shipping.methods.*', 'admin.shipping.settings'],
        'catalog' => ['admin.catalog.products.*', 'admin.catalog.categories.*', 'admin.catalog.taxonomy.*', 'admin.catalog.attributes.*', 'admin.catalog.import.*', 'admin.search.synonyms.*', 'admin.search.zero.*'],
        'inventory' => ['admin.inventory.index', 'admin.inventory.transfers.*', 'admin.inventory.counts.*', 'admin.inventory.imports.*', 'admin.inventory.reports.low', 'admin.inventory.reports.aging'],
        'pricing' => ['admin.pricing.index', 'admin.pricing.bulk', 'admin.promotions.coupons.*', 'admin.promotions.promotions.*'],
        'services' => ['admin.bookings.index', 'admin.bookings.calendar', 'admin.bookings.setup.*', 'admin.digital.assets.*', 'admin.digital.entitlements.*', 'admin.digital.policies.*'],
        'finance' => ['admin.payments.today', 'admin.payments.index', 'admin.payments.refunds.*', 'admin.payments.cod.*', 'admin.payments.reconciliation.*', 'admin.payments.settings'],
        'customers' => ['admin.support.index', 'admin.support.canned.*', 'admin.support.contact', 'admin.reviews.*', 'admin.privacy.*', 'admin.notifications.templates', 'admin.notifications.logs'],
        'content' => ['admin.cms.home.*', 'admin.cms.pages.*', 'admin.cms.banners.*', 'admin.cms.faq.*', 'admin.cms.policies.*', 'admin.cms.menus.*', 'admin.cms.translations'],
        'reports' => ['admin.reports.sales', 'admin.reports.index', 'admin.reports.exports'],
        'security' => ['admin.audit.*', 'admin.security.activity', 'admin.security.events', 'admin.security.logins', 'admin.sessions'],
        'system' => ['admin.ops.index', 'admin.ops.health', 'admin.ops.queues', 'admin.ops.scheduler', 'admin.ops.failed', 'admin.ops.integrity', 'admin.ops.about'],
        'administration' => ['admin.settings.*', 'admin.staff.*', 'admin.roles.*'],
    ];

    /** Route names shown on their own above the areas. */
    private const TOP = ['admin.dashboard'];

    public const OTHER = 'other';

    /** @var list<array{label: string, route: string, permission: ?string, order: int, params: array<string, string>}> */
    private array $items = [];

    /** @param  array<string, string>  $params  route parameters (e.g. ['type' => 'brands']) */
    public function add(string $labelKey, string $route, ?string $permission, int $order = 100, array $params = []): void
    {
        $this->items[] = ['label' => $labelKey, 'route' => $route, 'permission' => $permission, 'order' => $order, 'params' => $params];
    }

    /** @return list<array{label: string, route: string, permission: ?string, order: int, params: array<string, string>}> every entry any module registered, whoever is looking */
    public function all(): array
    {
        return $this->items;
    }

    /** @return list<array{label: string, route: string, permission: ?string, order: int, params: array<string, string>}> what this viewer may see, flat, by the modules' own order */
    public function visibleFor(?Authenticatable $user): array
    {
        $items = array_values(array_filter(
            $this->items,
            fn (array $item) => $item['permission'] === null || ($user !== null && method_exists($user, 'can') && $user->can($item['permission'])),
        ));

        usort($items, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $items;
    }

    /**
     * What the sidebar shows: the stand-alone entries, then the areas in their fixed order, each with the entries this viewer may see.
     *
     * @return array{top: list<array{label: string, route: string, permission: ?string, order: int, params: array<string, string>}>, areas: list<array{key: string, items: list<array{label: string, route: string, permission: ?string, order: int, params: array<string, string>}>}>}
     */
    public function groupedFor(?Authenticatable $user): array
    {
        $top = [];
        $byArea = [];
        foreach ($this->visibleFor($user) as $item) {
            if (in_array($item['route'], self::TOP, true)) {
                $top[] = $item;

                continue;
            }
            [$area, $rank] = $this->place($item['route']);
            $byArea[$area][] = ['rank' => $rank, 'item' => $item];
        }

        $areas = [];
        foreach ([...array_keys(self::AREAS), self::OTHER] as $key) {
            if (! isset($byArea[$key])) {
                continue;
            }
            $entries = $byArea[$key];
            usort($entries, fn (array $a, array $b) => [$a['rank'], $a['item']['order']] <=> [$b['rank'], $b['item']['order']]);
            $areas[] = ['key' => $key, 'items' => array_map(fn (array $e): array => $e['item'], $entries)];
        }

        return ['top' => $top, 'areas' => $areas];
    }

    /** @return array{0: string, 1: int} the area a route name belongs to, and its position within it */
    public function place(string $route): array
    {
        foreach (self::AREAS as $area => $patterns) {
            foreach ($patterns as $rank => $pattern) {
                if (Str::is($pattern, $route)) {
                    return [$area, $rank];
                }
            }
        }

        return [self::OTHER, 0];
    }
}
