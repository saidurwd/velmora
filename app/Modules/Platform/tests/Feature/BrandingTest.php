<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\Support\Branding;
use App\Modules\Platform\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
});

it('defaults to the blue brand and shows the store name', function () {
    $branding = app(Branding::class);

    expect($branding->color())->toBe('#1d4ed8')
        ->and($branding->cssVariables())->toContain('--color-brand-600:#1d4ed8')
        ->and($branding->logoUrl())->toBeNull();
});

it('lets an authorised admin change name, taglines, colour and logo', function () {
    $staff = catalogStaff(['settings.view', 'settings.manage']);

    $this->actingAs($staff, 'admin')->get('/admin/settings/branding')->assertOk();
    $this->actingAs($staff, 'admin')->put('/admin/settings/branding', [
        'name' => 'Velmora', 'color' => '#0b5ed7', 'tagline' => ['en' => 'Everyday, delivered', 'bn' => 'প্রতিদিনের পণ্য, দোরগোড়ায়'],
        'logo' => UploadedFile::fake()->image('logo.png', 900, 300),
    ])->assertRedirect('/admin/settings/branding');

    $branding = app(Branding::class);
    expect($branding->name())->toBe('Velmora')
        ->and($branding->color())->toBe('#0b5ed7')
        ->and($branding->tagline('bn'))->toBe('প্রতিদিনের পণ্য, দোরগোড়ায়')
        ->and($branding->logoUrl())->not->toBeNull();

    $stored = app(Settings::class)->string('brand.logo');
    Storage::disk('media')->assertExists($stored);
    [$w, $h] = getimagesizefromstring(Storage::disk('media')->get($stored));
    expect($w)->toBeLessThanOrEqual(640)->and($h)->toBeLessThanOrEqual(200);

    $this->get('/en')->assertOk()->assertSee('--color-brand-600:#0b5ed7', false);
});

it('falls back to the English tagline when a language has none', function () {
    app(Settings::class)->set('brand.tagline.en', 'Everyday, delivered');

    expect(app(Branding::class)->tagline('bn'))->toBe('Everyday, delivered');
});

it('refuses a colour that white text could not be read on', function () {
    $staff = catalogStaff(['settings.manage']);

    $this->actingAs($staff, 'admin')->put('/admin/settings/branding', ['name' => 'Velmora', 'color' => '#ffee00'])->assertSessionHasErrors('color');
    $this->actingAs($staff, 'admin')->put('/admin/settings/branding', ['name' => 'Velmora', 'color' => 'blue'])->assertSessionHasErrors('color');
    expect(app(Branding::class)->color())->toBe('#1d4ed8');
});

it('refuses non-images and svg as a logo, and replaces the old file', function () {
    $staff = catalogStaff(['settings.manage']);

    $this->actingAs($staff, 'admin')->put('/admin/settings/branding', ['name' => 'V', 'color' => '#1d4ed8', 'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertSessionHasErrors('logo');

    $this->actingAs($staff, 'admin')->put('/admin/settings/branding', ['name' => 'V', 'color' => '#1d4ed8', 'logo' => UploadedFile::fake()->image('a.png', 300, 100)]);
    $first = app(Settings::class)->string('brand.logo');
    $this->actingAs($staff, 'admin')->put('/admin/settings/branding', ['name' => 'V', 'color' => '#1d4ed8', 'logo' => UploadedFile::fake()->image('b.png', 300, 100)]);

    Storage::disk('media')->assertMissing($first);
    Storage::disk('media')->assertExists(app(Settings::class)->string('brand.logo'));
});

it('gates the branding screen by permission', function () {
    $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/settings/branding')->assertForbidden();
    $this->actingAs(catalogStaff(['settings.view']), 'admin')->put('/admin/settings/branding', ['name' => 'X', 'color' => '#1d4ed8'])->assertForbidden();
});

it('computes contrast correctly', function () {
    expect(Branding::contrastWithWhite('#000000'))->toBeGreaterThan(20)
        ->and(Branding::contrastWithWhite('#ffffff'))->toBeLessThan(1.1)
        ->and(Branding::contrastWithWhite('#1d4ed8'))->toBeGreaterThan(Branding::MIN_CONTRAST);
});
