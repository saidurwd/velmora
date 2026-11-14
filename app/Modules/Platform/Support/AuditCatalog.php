<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * What the audit actions mean to the security and activity screens: which ones are about signing in, how serious a security event is, and which menu area
 * an action belongs to. In one place, so the screens and their tests agree.
 */
final class AuditCatalog
{
    /** Signing in and out, for login history: action => what happened (success | failure | locked | signout). */
    public const SIGN_IN = [
        'auth.login' => 'success',
        'auth.api_login' => 'success',
        'auth.mfa_passed' => 'success',
        'auth.recovery_code_used' => 'success',
        'auth.login_failed' => 'failure',
        'auth.mfa_failed' => 'failure',
        'auth.otp_failed' => 'failure',
        'auth.account_locked' => 'locked',
        'auth.logout' => 'signout',
        'auth.api_sessions_ended' => 'signout',
        'auth.api_logout_all' => 'signout',
        'auth.api_refresh_reuse' => 'locked',
    ];

    /** Security events and how much they matter: high (look at it today), medium (worth knowing), info. */
    public const SECURITY = [
        'auth.account_locked' => 'high',
        'auth.api_refresh_reuse' => 'high',
        'auth.recovery_code_used' => 'high',
        'rbac.super_admin_bypass' => 'high',
        'staff.super_admin_created' => 'high',
        'role.deleted' => 'high',
        'integrations.changed' => 'high',
        'settings.maintenance_enabled' => 'high',
        'staff.created' => 'medium',
        'staff.updated' => 'medium',
        'staff.disabled' => 'medium',
        'staff.enabled' => 'medium',
        'role.created' => 'medium',
        'role.updated' => 'medium',
        'audit.exported' => 'medium',
        'auth.password_reset' => 'medium',
        'auth.password_changed' => 'medium',
        'auth.email_changed' => 'medium',
        'auth.phone_changed' => 'medium',
        'auth.mfa_failed' => 'medium',
        'settings.maintenance_disabled' => 'medium',
        'features.changed' => 'medium',
        'features.reset' => 'medium',
        'payments.freeze' => 'medium',
        'payments.refund_limit' => 'medium',
        'privacy.erasure_approved' => 'medium',
        'reports.export_downloaded' => 'medium',
        'auth.login_failed' => 'info',
        'auth.otp_failed' => 'info',
        'auth.mfa_enrolled' => 'info',
        'auth.password_set' => 'info',
    ];

    /** Failures counted in the "last 24 hours" summary and its "most failures from" list. */
    public const FAILURES = ['auth.login_failed', 'auth.mfa_failed', 'auth.otp_failed'];

    /** Action prefix (the part before the first dot) => menu area, for the activity filter. Unknown prefixes fall under "other". */
    public const AREAS = [
        'order' => 'sales', 'return' => 'sales',
        'shipment' => 'fulfilment', 'shipping' => 'fulfilment', 'courier' => 'fulfilment',
        'product' => 'catalog', 'variant' => 'catalog', 'category' => 'catalog', 'attribute' => 'catalog', 'brand' => 'catalog', 'tag' => 'catalog', 'collection' => 'catalog', 'media' => 'catalog', 'search' => 'catalog',
        'stock' => 'inventory', 'inventory' => 'inventory',
        'price' => 'pricing', 'coupon' => 'pricing', 'promotion' => 'pricing',
        'bookings' => 'services', 'digital' => 'services',
        'payment' => 'finance', 'payments' => 'finance', 'refund' => 'finance', 'cod' => 'finance', 'settlement' => 'finance',
        'support' => 'customers', 'reviews' => 'customers', 'privacy' => 'customers', 'notifications' => 'customers', 'account' => 'customers',
        'cms' => 'content',
        'reports' => 'reports',
        'auth' => 'security', 'audit' => 'security', 'rbac' => 'security',
        'ops' => 'system', 'features' => 'system', 'integrations' => 'system',
        'settings' => 'administration', 'staff' => 'administration', 'role' => 'administration', 'bulk' => 'administration',
    ];

    public static function area(string $action): string
    {
        return self::AREAS[strstr($action, '.', true) ?: $action] ?? 'other';
    }

    /**
     * The action prefixes of one area, for filtering in the database.
     *
     * @return list<string>
     */
    public static function prefixesOf(string $area): array
    {
        return array_keys(array_filter(self::AREAS, fn (string $a) => $a === $area));
    }

    public static function severity(string $action): string
    {
        return self::SECURITY[$action] ?? 'info';
    }

    /** The sentence that follows a person's name in the activity log ("confirmed an order"), in the current language. */
    public static function sentence(string $action): string
    {
        return self::word('platform::activity.actions', $action) ?? (string) __('platform::activity.unknown');
    }

    /** What a sign-in action is called in login history ("Wrong password"), in the current language. */
    public static function loginLabel(string $action): string
    {
        return self::word('platform::security.login_events', $action) ?? $action;
    }

    /**
     * One entry of a translation table keyed by action code. The codes contain dots, which a translation key would read as nesting, so the whole table is
     * read once and the code looked up in it.
     */
    private static function word(string $table, string $action): ?string
    {
        $words = __($table);

        return is_array($words) && is_string($words[$action] ?? null) ? $words[$action] : null;
    }
}
