<?php

declare(strict_types=1);

/*
 * Phase 8 acceptance criteria that are about what code must NEVER do, checked on the source itself:
 *  - services and digital goods never touch stock or shipping (they have no use for either);
 *  - no code path exposes a permanent public address for a digital file: a file is only ever reached through a short-lived signed address.
 */

/** @return list<string> the PHP source files (not tests) of a module */
function phase8Sources(string $module): array
{
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path("Modules/{$module}"), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->getExtension() === 'php' && ! str_contains($path, '/tests/')) {
            $found[] = $path;
        }
    }

    return $found;
}

it('never lets bookings or digital goods touch stock or shipping', function (string $module) {
    $offenders = [];
    foreach (phase8Sources($module) as $path) {
        $code = (string) file_get_contents($path);
        foreach (['App\\Modules\\Inventory', 'App\\Modules\\Shipping'] as $namespace) {
            if (str_contains($code, $namespace)) {
                $offenders[] = basename($path)." uses {$namespace}";
            }
        }
        if (preg_match('/[\'"`\s](stock_levels|stock_movements|stock_reservations|stock_locations|stock_transfers|shipments|shipment_items|courier_accounts)[\'"`\s]/', $code) === 1) {
            $offenders[] = basename($path).' names a stock or shipping table';
        }
    }

    expect($offenders)->toBe([]);
})->with(['Bookings', 'DigitalProducts']);

it('has no permanent public address for a digital file', function () {
    $offenders = [];
    foreach (phase8Sources('DigitalProducts') as $path) {
        $code = (string) file_get_contents($path);
        foreach (['->url(', 'Storage::url(', 'asset(', "'public'"] as $needle) {
            if (str_contains($code, $needle)) {
                $offenders[] = basename($path)." contains {$needle}";
            }
        }
        if (preg_match('/->temporaryUrl\(/', $code) === 1 && ! str_ends_with($path, 'Actions/DownloadDigitalFile.php')) {
            $offenders[] = basename($path).' makes a signed address outside DownloadDigitalFile';   // one place decides who gets a file, and for how long
        }
    }

    expect($offenders)->toBe([])
        ->and((int) config('digital.link_ttl_seconds'))->toBeGreaterThan(0)->toBeLessThanOrEqual(120);   // and that address is short-lived
});
