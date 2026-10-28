<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
});

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

it('creates and edits an attribute with bilingual values', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create', 'catalog.update']);

    $this->actingAs($staff, 'admin')->post('/admin/catalog/attributes', [
        'code' => 'color', 'kind' => 'option', 'is_filterable' => '1',
        'names' => ['en' => 'Colour', 'bn' => 'রঙ'],
        'values' => "red | Red | লাল\nblue | Blue | নীল",
    ])->assertRedirect('/admin/catalog/attributes');

    $this->actingAs($staff, 'admin')->get('/admin/catalog/attributes')->assertOk()->assertSee('Colour');
    $this->actingAs($staff, 'admin')->get('/admin/catalog/attributes/color/edit')->assertOk()->assertSee('red | Red | লাল', false);

    $this->actingAs($staff, 'admin')->put('/admin/catalog/attributes/color', [
        'code' => 'color', 'kind' => 'option', 'names' => ['en' => 'Colour'], 'values' => "red | Red | লাল\ngreen | Green | সবুজ",
    ])->assertRedirect();

    expect(DB::table('attribute_values')->count())->toBe(3);
});

it('rejects an attribute without an English name and forbids viewers from creating', function () {
    $this->actingAs(catalogStaff(['catalog.create']), 'admin')->post('/admin/catalog/attributes', ['code' => 'size', 'kind' => 'option', 'names' => ['en' => '']])->assertSessionHasErrors('names.en');
    $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/catalog/attributes/create')->assertForbidden();
});
