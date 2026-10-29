<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Orders\Services\AddressFingerprint;
use App\Modules\Orders\Services\CodRiskAssessor;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->admin = catalogStaff(['settings.view', 'settings.manage', 'orders.view', 'orders.cancel']);
    $this->viewer = catalogStaff(['settings.view', 'orders.view']);
    $this->form = fn (array $over = []) => array_replace(['enabled' => '1', 'max_open_cod_per_phone' => '3', 'high_value_cod' => '10000', 'velocity_orders_per_day' => '5', 'address_max_phones' => '3'], $over);
    $this->save = fn (array $over = [], $as = null) => $this->actingAs($as ?? $this->admin, 'admin')->put('/admin/orders/fraud/settings', ($this->form)($over));
    $this->assess = fn (string $phone = '+8801712345678', int $total = 50000) => app(CodRiskAssessor::class)->assess($phone, null, $total, 'cod', false);
});

describe('who can do what', function () {
    it('keeps both screens away from anyone without the permissions', function () {
        $nobody = catalogStaff(['catalog.view']);

        $this->get('/admin/orders/fraud/settings')->assertRedirect();
        $this->actingAs($nobody, 'admin')->get('/admin/orders/fraud/settings')->assertForbidden();
        $this->actingAs($nobody, 'admin')->get('/admin/orders/fraud/blocklist')->assertForbidden();
        ($this->save)([], $nobody)->assertForbidden();
    });

    it('lets a viewer look at the limits and the list but change nothing', function () {
        $this->actingAs($this->viewer, 'admin')->get('/admin/orders/fraud/settings')->assertOk()->assertSee('You can look at these limits')->assertDontSee('>Save<', false);
        ($this->save)(['max_open_cod_per_phone' => '9'], $this->viewer)->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->get('/admin/orders/fraud/blocklist')->assertOk()->assertDontSee('Block this number');
        $this->actingAs($this->viewer, 'admin')->post('/admin/orders/fraud/blocklist', ['phone' => '01712345678'])->assertForbidden();
    });
});

describe('the limits', function () {
    it('shows what is in force now, and the defaults on a fresh install', function () {
        $this->actingAs($this->admin, 'admin')->get('/admin/orders/fraud/settings')->assertOk()->assertSee('Cash-on-delivery rules')
            ->assertSee('value="3"', false)->assertSee('value="10000.00"', false)->assertSee('value="5"', false);
    });

    it('saves them and the next assessment uses them at once', function () {
        fakeOrders([1, 2]);   // two open cash orders (pending) from this phone
        DB::table('orders')->whereIn('id', [1, 2])->get();
        expect(($this->assess)()->tooManyOpenCod)->toBeFalse();   // the default allows three

        ($this->save)(['max_open_cod_per_phone' => '2'])->assertRedirect('/admin/orders/fraud/settings')->assertSessionHas('status');

        expect(($this->assess)()->tooManyOpenCod)->toBeTrue()->and(($this->assess)()->maxOpenCod)->toBe(2)
            ->and(DB::table('audit_events')->where('action', 'settings.fraud.updated')->count())->toBe(1);
    });

    it('moves the high-value limit, turns it off with 0, and reads Bangla digits', function () {
        $needs = fn (int $total) => app(CodRiskAssessor::class)->requiresPhoneConfirmation($total, 'cod');
        expect($needs(1_000_001))->toBeTrue()->and($needs(1_000_000))->toBeFalse();

        ($this->save)(['high_value_cod' => '২০,০০০'])->assertRedirect();
        expect($needs(1_000_001))->toBeFalse()->and($needs(2_000_001))->toBeTrue()->and((int) app(Settings::class)->get('fraud.high_value_cod_minor'))->toBe(2_000_000);

        ($this->save)(['high_value_cod' => '0'])->assertRedirect();
        expect($needs(99_999_999))->toBeFalse();
        $this->actingAs($this->admin, 'admin')->get('/admin/orders/fraud/settings')->assertSee('value="0"', false);
    });

    it('can switch every rule off, and back on', function () {
        fakeOrders([1, 2, 3, 4]);
        expect(($this->assess)()->tooManyOpenCod)->toBeTrue();

        ($this->save)(['enabled' => '0'])->assertRedirect();   // a real unchecked box sends nothing, '0' is the same
        expect(($this->assess)()->tooManyOpenCod)->toBeFalse();

        $this->actingAs($this->admin, 'admin')->put('/admin/orders/fraud/settings', array_diff_key(($this->form)(), ['enabled' => 1]))->assertRedirect();   // an unticked box sends nothing: off
        expect(($this->assess)()->tooManyOpenCod)->toBeFalse();

        ($this->save)(['enabled' => '1'])->assertRedirect();
        expect(($this->assess)()->tooManyOpenCod)->toBeTrue()->and(DB::table('audit_events')->where('action', 'settings.fraud.updated')->count())->toBe(3);
    });

    it('refuses numbers that are not allowed and keeps the old limits', function () {
        $bad = fn (array $over, string $field) => ($this->save)($over)->assertSessionHasErrors($field);

        $bad(['max_open_cod_per_phone' => '-1'], 'max_open_cod_per_phone');
        $bad(['max_open_cod_per_phone' => '51'], 'max_open_cod_per_phone');
        $bad(['max_open_cod_per_phone' => 'three'], 'max_open_cod_per_phone');
        $bad(['velocity_orders_per_day' => '201'], 'velocity_orders_per_day');
        $bad(['address_max_phones' => ''], 'address_max_phones');
        $bad(['high_value_cod' => 'lots'], 'high_value_cod');
        $bad(['high_value_cod' => '-5'], 'high_value_cod');
        $bad(['high_value_cod' => '1.234'], 'high_value_cod');
        expect(DB::table('settings')->where('key', 'like', 'fraud.%')->count())->toBe(0);
    });
});

describe('the block list', function () {
    it('blocks a phone typed any way, keeps only its hash, and the rules then refuse it', function () {
        $this->actingAs($this->admin, 'admin')->post('/admin/orders/fraud/blocklist', ['phone' => '০১৭১২ ৩৪৫৬৭৮', 'reason' => 'Refused three parcels'])->assertRedirect('/admin/orders/fraud/blocklist')->assertSessionHas('status');

        $row = DB::table('order_blocklist')->first();
        expect($row->kind)->toBe('phone')->and($row->value_hash)->toBe(AddressFingerprint::phone('+8801712345678'))->and($row->hint)->toContain('****')->and($row->reason)->toBe('Refused three parcels')
            ->and(json_encode($row))->not->toContain('1712345678')
            ->and(($this->assess)()->blocked)->toBeTrue()->and(($this->assess)('+8801812345678')->blocked)->toBeFalse()
            ->and(DB::table('audit_events')->where('action', 'order.blocklist.added')->count())->toBe(1);
    });

    it('shows the entries, and a repeated block is still one entry', function () {
        foreach ([1, 2] as $i) {
            $this->actingAs($this->admin, 'admin')->post('/admin/orders/fraud/blocklist', ['phone' => '01712345678', 'reason' => "again {$i}"]);
        }

        expect(DB::table('order_blocklist')->count())->toBe(1);
        $this->actingAs($this->admin, 'admin')->get('/admin/orders/fraud/blocklist')->assertOk()->assertSee('again 2')->assertSee('Blocked')->assertSee('Stop blocking')->assertDontSee('1712345678');
    });

    it('switches an entry off and on again, and the rules follow', function () {
        $this->actingAs($this->admin, 'admin')->post('/admin/orders/fraud/blocklist', ['phone' => '01712345678']);
        $id = DB::table('order_blocklist')->value('id');

        $this->actingAs($this->admin, 'admin')->post("/admin/orders/fraud/blocklist/{$id}/toggle", ['active' => '0'])->assertRedirect('/admin/orders/fraud/blocklist');
        expect(($this->assess)()->blocked)->toBeFalse();
        $this->actingAs($this->admin, 'admin')->get('/admin/orders/fraud/blocklist')->assertSee('Not blocked')->assertSee('Block again');

        $this->actingAs($this->admin, 'admin')->post("/admin/orders/fraud/blocklist/{$id}/toggle", ['active' => '1'])->assertRedirect();
        expect(($this->assess)()->blocked)->toBeTrue()->and(DB::table('audit_events')->whereIn('action', ['order.blocklist.enabled', 'order.blocklist.disabled'])->count())->toBe(2);
    });

    it('refuses a phone that is not a phone, and an entry that does not exist', function () {
        $this->actingAs($this->admin, 'admin')->post('/admin/orders/fraud/blocklist', ['phone' => '12345'])->assertSessionHasErrors('phone');
        $this->actingAs($this->admin, 'admin')->post('/admin/orders/fraud/blocklist', ['phone' => ''])->assertSessionHasErrors('phone');
        $this->actingAs($this->admin, 'admin')->post('/admin/orders/fraud/blocklist/9999/toggle', ['active' => '0'])->assertNotFound();
        $this->actingAs($this->viewer, 'admin')->post('/admin/orders/fraud/blocklist/1/toggle', ['active' => '0'])->assertForbidden();
        expect(DB::table('order_blocklist')->count())->toBe(0);
    });

    it('lists an entry that has expired as expired, and an empty list says so', function () {
        $this->actingAs($this->admin, 'admin')->get('/admin/orders/fraud/blocklist')->assertSee('Nothing is blocked');

        DB::table('order_blocklist')->insert(['kind' => 'address', 'value_hash' => str_repeat('a', 64), 'hint' => 'Savar, Dhaka, Dhaka', 'is_active' => true, 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin, 'admin')->get('/admin/orders/fraud/blocklist')->assertSee('Savar, Dhaka, Dhaka')->assertSee('Expired')->assertSee('Address');
    });
});

it('is not mistaken for an order: "fraud" is not an order id', function () {
    $this->actingAs($this->admin, 'admin')->get('/admin/orders/fraud')->assertNotFound();
});

it('has Bangla wording for everything the cash-order rule screens say', function () {
    $keys = function (array $a, string $prefix = '') use (&$keys): array {
        $out = [];
        foreach ($a as $k => $v) {
            is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
        }

        return $out;
    };

    $en = $keys(require app_path('Modules/Orders/resources/lang/en/fraud.php'));
    $bn = $keys(require app_path('Modules/Orders/resources/lang/bn/fraud.php'));
    expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
});
