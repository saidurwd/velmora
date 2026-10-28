<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
});

/** @param list<string> $permissions */
function catalogStaff(array $permissions): User
{
    $staff = User::factory()->staff()->create(['email' => 'cat'.uniqid().'@example.com']);
    foreach ($permissions as $p) {
        $staff->givePermissionTo($p);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $staff;
}

function enBn(string $en, ?string $bn = null): array
{
    return ['en' => ['name' => $en], 'bn' => ['name' => $bn ?? '']];
}

it('gates every taxonomy screen by permission', function () {
    $viewer = catalogStaff(['catalog.view']);

    $this->actingAs($viewer, 'admin')->get('/admin/catalog/categories')->assertOk();
    $this->actingAs($viewer, 'admin')->get('/admin/catalog/categories/create')->assertForbidden();
    $this->actingAs($viewer, 'admin')->post('/admin/catalog/brands', ['translations' => enBn('Acme')])->assertForbidden();
});

it('sends guests to the admin login', function () {
    $this->get('/admin/catalog/brands')->assertRedirect();
});

it('creates a category with a Bangla translation and shows the missing badge when absent', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create']);

    $this->actingAs($staff, 'admin')->post('/admin/catalog/categories', ['translations' => enBn('Shoes', 'জুতা'), 'is_active' => '1'])->assertRedirect();
    $this->actingAs($staff, 'admin')->post('/admin/catalog/categories', ['translations' => enBn('Bags')])->assertRedirect();

    expect(Category::count())->toBe(2);

    $this->actingAs($staff, 'admin')->get('/admin/catalog/categories')->assertOk()->assertSee('Shoes')->assertSee('Bags');

    $bags = Category::query()->whereHas('translations', fn ($q) => $q->where('name', 'Bags'))->firstOrFail();
    $this->actingAs(catalogStaff(['catalog.view', 'catalog.update']), 'admin')->get("/admin/catalog/categories/{$bags->public_id}/edit")->assertOk()->assertSee('bg-amber-100', false);
});

it('requires the English name', function () {
    $staff = catalogStaff(['catalog.create']);

    $this->actingAs($staff, 'admin')->post('/admin/catalog/brands', ['translations' => enBn('', 'ব্র্যান্ড')])->assertSessionHasErrors('translations.en.name');
    expect(Brand::count())->toBe(0);
});

it('creates, edits and deletes a brand', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete']);

    $this->actingAs($staff, 'admin')->post('/admin/catalog/brands', ['translations' => enBn('Acme', 'অ্যাকমি')])->assertRedirect();
    $brand = Brand::firstOrFail();

    $this->actingAs($staff, 'admin')->put("/admin/catalog/brands/{$brand->public_id}", ['translations' => enBn('Acme 2', 'অ্যাকমি ২')])->assertRedirect();
    expect($brand->fresh()->translations->firstWhere('locale', 'en')?->name)->toBe('Acme 2');

    $this->actingAs($staff, 'admin')->delete("/admin/catalog/brands/{$brand->public_id}")->assertRedirect();
    expect(Brand::count())->toBe(0);
});

it('does not run a query per row on the category list', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create']);
    foreach (range(1, 12) as $i) {
        $this->actingAs($staff, 'admin')->post('/admin/catalog/categories', ['translations' => enBn("Cat {$i}", "ক্যাট {$i}")]);
    }

    DB::enableQueryLog();
    $this->actingAs($staff, 'admin')->get('/admin/catalog/categories')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(20);
});

it('renders the Bangla admin chrome', function () {
    $staff = catalogStaff(['catalog.view']);

    $this->actingAs($staff, 'admin')->withSession(['locale' => 'bn'])->get('/admin/catalog/categories')->assertOk();
    expect(trans('catalog::admin.categories.title', [], 'bn'))->toBe('ক্যাটাগরি');
});
