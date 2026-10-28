<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class, StockLocationSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $make = fn (string $sku, ProductType $type, bool $track) => (int) app(SaveProduct::class)->handle(Actor::system('t'), null, new ProductData($type, [new TranslationInput('en', "Item {$sku}")], defaultVariant: new VariantData($sku, trackInventory: $track)))->variants()->value('id');
    $this->physical = $make('BOX-1', ProductType::Physical, true);
    $this->digital = $make('EBOOK-1', ProductType::Digital, false);
});

it('searches variants and shows stock totals', function () {
    $staff = catalogStaff(['inventory.view']);

    $this->actingAs($staff, 'admin')->get('/admin/inventory?q=BOX')->assertOk()->assertSee('BOX-1');
    $this->actingAs($staff, 'admin')->get('/admin/inventory?q=EBOOK')->assertOk()->assertSee('EBOOK-1');
    $this->actingAs($staff, 'admin')->get('/admin/inventory/variants/NOPE')->assertNotFound();
});

it('receives, adjusts and writes off stock, leaving a ledger row each time', function () {
    $staff = catalogStaff(['inventory.view', 'inventory.adjust']);
    $url = '/admin/inventory/variants/BOX-1/adjust';

    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'receipt', 'quantity' => '১০'])->assertRedirect(); // Bangla digits
    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'write_off', 'quantity' => '2', 'note' => 'damaged'])->assertRedirect(); // sign is forced negative
    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'adjustment', 'quantity' => '-1', 'note' => 'recount'])->assertRedirect();

    expect((int) DB::table('stock_levels')->where('variant_id', $this->physical)->value('on_hand'))->toBe(7)
        ->and(DB::table('stock_movements')->where('variant_id', $this->physical)->orderBy('id')->pluck('delta')->map(fn ($d) => (int) $d)->all())->toBe([10, -2, -1]);

    $this->actingAs($staff, 'admin')->get('/admin/inventory/variants/BOX-1')->assertOk()->assertSee('damaged');
});

it('refuses removal below reserved stock, untracked items, bad input and a missing note', function () {
    $staff = catalogStaff(['inventory.adjust']);
    $url = '/admin/inventory/variants/BOX-1/adjust';
    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'receipt', 'quantity' => '5']);
    app(ReserveStock::class)->handle($this->physical, 4, 'cart', 'c1', 600);

    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'adjustment', 'quantity' => '-3', 'note' => 'x'])->assertSessionHasErrors('quantity'); // would leave 2 on hand, 4 reserved
    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'adjustment', 'quantity' => '-1'])->assertSessionHasErrors('note');
    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'receipt', 'quantity' => '-1'])->assertSessionHasErrors('quantity');
    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'receipt', 'quantity' => '1.5'])->assertSessionHasErrors('quantity');
    $this->actingAs($staff, 'admin')->post($url, ['location' => 'nowhere', 'reason' => 'receipt', 'quantity' => '1'])->assertSessionHasErrors('location');
    $this->actingAs($staff, 'admin')->post($url, ['location' => 'main', 'reason' => 'shipment', 'quantity' => '1'])->assertSessionHasErrors('reason'); // shipments come from the order flow
    $this->actingAs($staff, 'admin')->post('/admin/inventory/variants/EBOOK-1/adjust', ['location' => 'main', 'reason' => 'receipt', 'quantity' => '5'])->assertSessionHasErrors('quantity');

    expect((int) DB::table('stock_levels')->where('variant_id', $this->physical)->value('on_hand'))->toBe(5);
});

it('gates stock screens by permission', function () {
    $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/inventory')->assertForbidden();
    $this->actingAs(catalogStaff(['inventory.view']), 'admin')->post('/admin/inventory/variants/BOX-1/adjust', ['location' => 'main', 'reason' => 'receipt', 'quantity' => '1'])->assertForbidden();
});

it('pages the movement history without per-row queries', function () {
    $staff = catalogStaff(['inventory.view', 'inventory.adjust']);
    foreach (range(1, 12) as $i) {
        $this->actingAs($staff, 'admin')->post('/admin/inventory/variants/BOX-1/adjust', ['location' => 'main', 'reason' => 'receipt', 'quantity' => '1']);
    }

    DB::enableQueryLog();
    $this->actingAs($staff, 'admin')->get('/admin/inventory/variants/BOX-1')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(20);
});
