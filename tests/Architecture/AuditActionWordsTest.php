<?php

declare(strict_types=1);

/*
 * Every action the code writes to the audit trail has a plain-language sentence for the activity log, in English and Bangla
 * (app/Modules/Platform/resources/lang/{en,bn}/activity.php). Adding a new audit action without one fails here.
 */

/** Codes built at run time (a fixed start plus a value) with every value they can take. */
const DYNAMIC_AUDIT_ACTIONS = [
    'shipment.issue_resolved', 'shipment.issue_ignored', 'stock.count_approved', 'stock.count_rejected', 'digital.entitlement_extended',
    'digital.entitlement_downloads_added', 'digital.entitlement_downloads_reset', 'order.cancellation_approved', 'order.cancellation_rejected',
    'product.published', 'product.scheduled', 'product.unpublished', 'product.archived', 'brand.created', 'brand.updated', 'tag.created', 'tag.updated',
    'collection.created', 'collection.updated', 'settings.business_updated', 'settings.tax_updated', 'features.changed',
    'auth.api_refresh_reuse', 'auth.api_logout_all', 'auth.api_sessions_ended',
];

/** @return list<string> the literal action codes passed to ->record(...) anywhere in the modules (tests excluded) */
function auditActionsInCode(): array
{
    $codes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = (string) $file;
        if (! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }
        $source = (string) file_get_contents($path);
        // the first argument of each record( call, up to its first top-level comma
        preg_match_all('/(?:->|::)record\(\s*((?:[^,()]|\((?:[^()]|\([^()]*\))*\))*)/', $source, $calls);
        foreach ($calls[1] as $argument) {
            preg_match_all("/'([a-z_]+\\.[a-z_.]+)'/", $argument, $literals);
            foreach ($literals[1] as $code) {
                if (! str_ends_with($code, '_') && $code !== 'x.y') {
                    $codes[$code] = true;
                }
            }
        }
    }

    return array_keys($codes);
}

it('has an activity sentence in English and Bangla for every audit action', function () {
    $codes = array_values(array_unique([...auditActionsInCode(), ...DYNAMIC_AUDIT_ACTIONS]));
    $en = (require app_path('Modules/Platform/resources/lang/en/activity.php'))['actions'];
    $bn = (require app_path('Modules/Platform/resources/lang/bn/activity.php'))['actions'];

    expect(count($codes))->toBeGreaterThan(150)   // the scan itself works
        ->and(array_values(array_diff($codes, array_keys($en))))->toBe([])
        ->and(array_values(array_diff($codes, array_keys($bn))))->toBe([])
        ->and(array_values(array_diff(array_keys($en), array_keys($bn))))->toBe([]);
});
