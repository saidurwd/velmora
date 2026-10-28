<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Permissions;

/**
 * Single source of truth for staff permissions (`<resource>.<action>`) and the default roles. Later modules append
 * their own entries here when they ship admin screens; `rbac:matrix` regenerates docs/architecture/PERMISSION_MATRIX.md
 * and a test fails when the document is stale.
 */
final class PermissionCatalogue
{
    public const SUPER_ADMIN = 'Super Admin';

    /** Permission name => true when using it is audited even for Super Admin (money, staff, settings, PII). */
    public const SENSITIVE = [
        'staff.create', 'staff.update', 'staff.disable', 'roles.manage', 'settings.manage', 'payments.refund', 'payments.reconcile',
        'customers.view_pii', 'customers.export', 'reports.finance.view', 'reports.export', 'inventory.adjust', 'audit.view',
    ];

    /** @var array<string, list<string>> resource => actions */
    private const PERMISSIONS = [
        'dashboard' => ['view'],
        'staff' => ['view', 'create', 'update', 'disable'],
        'roles' => ['view', 'manage'],
        'audit' => ['view'],
        'settings' => ['view', 'manage'],
        'queues' => ['view'],
        'catalog' => ['view', 'create', 'update', 'publish', 'delete'],
        'pricing' => ['view', 'update'],
        'inventory' => ['view', 'adjust', 'transfer', 'count'],
        'orders' => ['view', 'update', 'cancel', 'export'],
        'payments' => ['view', 'refund', 'reconcile'],
        'shipping' => ['view', 'manage', 'create_shipment'],
        'customers' => ['view', 'view_pii', 'update', 'export'],
        'promotions' => ['view', 'manage'],
        'cms' => ['view', 'edit', 'publish'],
        'reviews' => ['moderate'],
        'support' => ['view', 'reply', 'manage'],
        'reports' => ['view', 'export'],
    ];

    /** Three-segment permissions that do not fit resource.action. */
    private const EXTRA = ['reports.finance.view'];

    /** @var array<string, list<string>> default role => permissions ('*' = every permission). Applied only when the role is first created. */
    private const ROLES = [
        self::SUPER_ADMIN => ['*'],
        'Admin' => ['*', '!roles.manage'],
        'Catalog Manager' => ['dashboard.view', 'catalog.*', 'pricing.*', 'inventory.view', 'cms.view', 'reviews.moderate'],
        'Order Manager' => ['dashboard.view', 'orders.view', 'orders.update', 'orders.cancel', 'orders.export', 'customers.view', 'shipping.view', 'shipping.create_shipment', 'payments.view', 'support.view'],
        'Warehouse' => ['dashboard.view', 'inventory.*', 'orders.view', 'shipping.view', 'shipping.create_shipment', 'catalog.view'],
        'Finance' => ['dashboard.view', 'payments.*', 'orders.view', 'orders.export', 'reports.view', 'reports.export', 'reports.finance.view', 'customers.view'],
        'Support' => ['dashboard.view', 'support.*', 'orders.view', 'customers.view', 'customers.view_pii', 'reviews.moderate'],
        'Content Editor' => ['dashboard.view', 'cms.*', 'catalog.view'],
        'Read-only' => ['dashboard.view', 'catalog.view', 'orders.view', 'inventory.view', 'payments.view', 'shipping.view', 'reports.view', 'promotions.view', 'cms.view'],
    ];

    /** @return list<string> */
    public static function permissions(): array
    {
        $all = [];
        foreach (self::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $all[] = "{$resource}.{$action}";
            }
        }

        return array_merge($all, self::EXTRA);
    }

    /** @return array<string, list<string>> resource => actions (for the permission matrix UI) */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::permissions() as $permission) {
            [$resource, $action] = explode('.', $permission, 2);
            $grouped[$resource][] = $action;
        }

        return $grouped;
    }

    /** @return list<string> */
    public static function roleNames(): array
    {
        return array_keys(self::ROLES);
    }

    /** @return list<string> default permissions for a system role, wildcards expanded */
    public static function defaultsFor(string $role): array
    {
        $granted = [];
        $revoked = [];

        foreach (self::ROLES[$role] ?? [] as $rule) {
            if ($rule === '*') {
                $granted = [...$granted, ...self::permissions()];
            } elseif (str_starts_with($rule, '!')) {
                $revoked[] = substr($rule, 1);
            } elseif (str_ends_with($rule, '.*')) {
                $prefix = substr($rule, 0, -1);
                $granted = [...$granted, ...array_filter(self::permissions(), fn (string $p) => str_starts_with($p, $prefix))];
            } else {
                $granted[] = $rule;
            }
        }

        return array_values(array_diff(array_unique($granted), $revoked));
    }

    public static function isSystemRole(string $role): bool
    {
        return isset(self::ROLES[$role]);
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    public static function isSensitive(string $permission): bool
    {
        return in_array($permission, self::SENSITIVE, true);
    }
}
