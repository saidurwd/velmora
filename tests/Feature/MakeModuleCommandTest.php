<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tests\Support\ModuleBoundaryChecker;

beforeEach(function () {
    $this->tmp = storage_path('framework/testing/modules-'.uniqid());
    (new Filesystem)->makeDirectory($this->tmp, 0755, true);
});

afterEach(fn () => (new Filesystem)->deleteDirectory($this->tmp));

it('generates a module skeleton that passes the boundary rules', function () {
    $this->artisan('make:module', ['name' => 'Orders', '--path' => $this->tmp])->assertSuccessful();

    foreach (['Actions', 'Contracts', 'Http/Controllers/Api', 'routes/web.php', 'resources/lang/bn', 'Database/migrations', 'tests/Feature', 'Providers/OrdersServiceProvider.php'] as $path) {
        expect(file_exists($this->tmp.'/Orders/'.$path))->toBeTrue("missing {$path}");
    }

    expect((new ModuleBoundaryChecker)->check($this->tmp))->toBe([]);
});

it('refuses duplicate or invalid module names', function () {
    $this->artisan('make:module', ['name' => 'Orders', '--path' => $this->tmp])->assertSuccessful();
    $this->artisan('make:module', ['name' => 'Orders', '--path' => $this->tmp])->assertFailed();
    $this->artisan('make:module', ['name' => 'bad name', '--path' => $this->tmp])->assertFailed();
});
