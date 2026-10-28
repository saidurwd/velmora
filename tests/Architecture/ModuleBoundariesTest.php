<?php

declare(strict_types=1);

use Tests\Support\ModuleBoundaryChecker;

it('keeps real modules inside the BD-11 boundaries', function () {
    expect((new ModuleBoundaryChecker)->check(app_path('Modules')))->toBe([]);
});

it('detects a deliberate violation in the fixture modules', function () {
    $violations = (new ModuleBoundaryChecker)->check(base_path('tests/Fixtures/Modules'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['reference'])->toBe('App\Modules\Beta\Models')
        ->and($violations[0]['file'])->toContain('Alpha/Actions/DoThing.php');
});

it('lets modules use another module only through contracts, DTOs, enums and events', function () {
    $violations = (new ModuleBoundaryChecker)->check(base_path('tests/Fixtures/Modules'));

    // Beta\Contracts\Thing is referenced by Alpha and is allowed; only Models is flagged.
    expect(collect($violations)->pluck('reference')->all())->not->toContain('App\Modules\Beta\Contracts');
});

arch('strict types everywhere in app code')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();
