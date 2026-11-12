<?php

declare(strict_types=1);

/*
 * Production installs run `composer install --no-dev`. Anything that runs at boot (config files, bootstrap) must not name a class from a
 * dev-only package, or the whole app dies while loading its configuration (this happened once with Scramble's config; the Browser tests job,
 * which installs without dev packages, caught it).
 */

it('lets config and bootstrap files load without any dev-only package', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    $installed = json_decode((string) file_get_contents(base_path('vendor/composer/installed.json')), true, 512, JSON_THROW_ON_ERROR)['packages'];

    $devNames = array_keys($composer['require-dev'] ?? []);
    $prefixes = [];
    foreach ($installed as $package) {
        if (in_array($package['name'], $devNames, true)) {
            foreach (array_keys($package['autoload']['psr-4'] ?? []) as $prefix) {
                if (! in_array($prefix, ['App\\', 'Tests\\', 'Database\\Factories\\', 'Database\\Seeders\\'], true)) { // the project's own namespaces
                    $prefixes[] = rtrim($prefix, '\\').'\\';
                }
            }
        }
    }
    expect($prefixes)->not->toBe([]);

    $offenders = [];
    foreach ([...glob(base_path('config/*.php')), ...glob(base_path('bootstrap/*.php'))] as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue; // comments are T_COMMENT / T_DOC_COMMENT and are ignored
            }
            $name = ltrim($token[1], '\\').'\\';
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $offenders[] = basename($file).' names '.$token[1];
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
