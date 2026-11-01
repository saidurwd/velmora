<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    shippingWorld($this);   // two products (TEE-1, MUG-1) with 50 each in "main"
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    DB::table('stock_locations')->insert(['public_id' => strtolower((string) Str::ulid()), 'code' => 'shop', 'name' => 'Dhanmondi shop', 'type' => 'store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->viewer = catalogStaff(['inventory.view']);
    $this->mover = catalogStaff(['inventory.view', 'inventory.transfer']);
    $this->counter = catalogStaff(['inventory.view', 'inventory.count']);
    $this->approver = catalogStaff(['inventory.view', 'inventory.adjust']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn ($staff) => $this->actingAs($staff, 'admin');
    $this->level = fn (int $variantId, string $code = 'main') => DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')->where('variant_id', $variantId)->where('stock_locations.code', $code)->first(['on_hand', 'reserved']);
    $this->form = fn (array $lines, string $from = 'main', string $to = 'shop') => ['from' => $from, 'to' => $to, 'lines' => $lines, 'note' => 'Restock the shop'];
    $this->send = fn (array $lines = [['sku' => 'TEE-1', 'qty' => '10'], ['sku' => 'MUG-1', 'qty' => '5']]) => ($this->as)($this->mover)->post('/admin/inventory/transfers', ($this->form)($lines));
    $this->openCount = function (?string $skus = null) {
        ($this->as)($this->counter)->post('/admin/inventory/counts', ['location' => 'main', 'skus' => $skus, 'note' => 'Monthly']);

        return DB::table('stock_counts')->latest('id')->first();
    };
});

describe('who can do what', function () {
    it('keeps every screen away from guests and staff without stock permissions, and lets a viewer look', function () {
        $transfer = ($this->send)()->assertRedirect();
        $count = ($this->openCount)();
        $public = DB::table('stock_transfers')->value('public_id');
        $urls = ['/admin/inventory/transfers', "/admin/inventory/transfers/{$public}", '/admin/inventory/counts', "/admin/inventory/counts/{$count->public_id}", '/admin/inventory/reports/low-stock', '/admin/inventory/reports/aging'];

        foreach ($urls as $url) {
            $this->app['auth']->forgetGuards();
            $this->get($url)->assertRedirect();
        }
        foreach ($urls as $url) {
            ($this->as)($this->nobody)->get($url)->assertForbidden();
            ($this->as)($this->viewer)->get($url)->assertOk();
        }
        ($this->as)($this->viewer)->get('/admin/inventory/transfers/create')->assertForbidden();
        ($this->as)($this->mover)->get('/admin/inventory/transfers/create')->assertOk();
    });

    it('keeps each action to the permission it needs, and a different permission is not enough', function () {
        ($this->send)();
        $count = ($this->openCount)();
        $transfer = DB::table('stock_transfers')->value('public_id');
        $acts = [
            ['/admin/inventory/transfers', ['from' => 'main', 'to' => 'shop'], 'inventory.transfer'],
            ["/admin/inventory/transfers/{$transfer}/receive", [], 'inventory.transfer'],
            ["/admin/inventory/transfers/{$transfer}/cancel", ['reason' => 'x'], 'inventory.transfer'],
            ['/admin/inventory/counts', ['location' => 'main'], 'inventory.count'],
            ["/admin/inventory/counts/{$count->public_id}/record", ['counted' => [1 => 1]], 'inventory.count'],
            ["/admin/inventory/counts/{$count->public_id}/submit", [], 'inventory.count'],
            ["/admin/inventory/counts/{$count->public_id}/cancel", [], 'inventory.count'],
            ["/admin/inventory/counts/{$count->public_id}/approve", [], 'inventory.adjust'],
            ["/admin/inventory/counts/{$count->public_id}/reject", ['reason' => 'x'], 'inventory.adjust'],
        ];
        $holders = ['inventory.transfer' => $this->mover, 'inventory.count' => $this->counter, 'inventory.adjust' => $this->approver];
        foreach ($acts as [$url, $data, $needs]) {
            ($this->as)($this->viewer)->post($url, $data)->assertForbidden();
            foreach ($holders as $permission => $staff) {
                if ($permission !== $needs) {
                    ($this->as)($staff)->post($url, $data)->assertForbidden();
                }
            }
        }
        expect(DB::table('stock_transfers')->count())->toBe(1)->and(DB::table('stock_counts')->value('status'))->toBe('open');   // none of that changed anything
    });
});

describe('transfers', function () {
    it('sends stock from the typed SKUs, adds up a SKU typed twice, and shows the transfer', function () {
        ($this->send)([['sku' => 'TEE-1', 'qty' => '6'], ['sku' => '', 'qty' => ''], ['sku' => 'TEE-1', 'qty' => '4'], ['sku' => 'MUG-1', 'qty' => '5']])->assertSessionHas('status', 'The transfer was made. The stock is now on its way.');

        $t = DB::table('stock_transfers')->first();
        expect(($this->level)($this->tee)->on_hand)->toBe(40)->and(($this->level)($this->mug)->on_hand)->toBe(45)->and(DB::table('stock_transfer_items')->where('transfer_id', $t->id)->where('variant_id', $this->tee)->value('sent_qty'))->toBe(10);
        ($this->as)($this->viewer)->get("/admin/inventory/transfers/{$t->public_id}")->assertOk()->assertSee('TEE-1')->assertSee('Cotton Tee')->assertSee('On its way')->assertDontSee('Stock has arrived');
        ($this->as)($this->mover)->get("/admin/inventory/transfers/{$t->public_id}")->assertOk()->assertSee('Stock has arrived');
        ($this->as)($this->viewer)->get('/admin/inventory/transfers')->assertOk()->assertSee(strtoupper(substr($t->public_id, -8)))->assertSee('Dhanmondi shop');
    });

    it('explains a refusal in words: an unknown SKU, the same place twice, nothing chosen, more than is free, a bad number', function () {
        $post = fn (array $data) => ($this->as)($this->mover)->post('/admin/inventory/transfers', $data);

        $post(($this->form)([['sku' => 'NOPE-9', 'qty' => '1']]))->assertSessionHasErrors('transfer')->assertSessionHasErrorsIn('default', ['transfer' => 'That product was not found. (NOPE-9)']);
        $post(($this->form)([['sku' => 'TEE-1', 'qty' => '1']], 'main', 'main'))->assertSessionHasErrors(['transfer' => 'Choose two different places.']);
        $post(($this->form)([['sku' => '', 'qty' => '']]))->assertSessionHasErrors(['transfer' => 'Choose what to move.']);
        $post(($this->form)([['sku' => 'TEE-1', 'qty' => '51']]))->assertSessionHasErrors(['transfer' => 'Not enough of one product is free to move (the rest is promised to orders). Nothing was moved.']);
        $post(($this->form)([['sku' => 'TEE-1', 'qty' => 'abc']]))->assertSessionHasErrors('lines.0.qty');
        $post(($this->form)([['sku' => 'TEE-1', 'qty' => '1']], 'main', 'nowhere'))->assertSessionHasErrors(['transfer' => 'That stock location was not found or is switched off.']);
        expect(DB::table('stock_transfers')->count())->toBe(0)->and(($this->level)($this->tee)->on_hand)->toBe(50);
    });

    it('receives what arrived and records the rest as lost, once', function () {
        ($this->send)();
        $t = DB::table('stock_transfers')->first();
        $receive = fn (array $data = []) => ($this->as)($this->mover)->post("/admin/inventory/transfers/{$t->public_id}/receive", $data);

        $receive(['received' => [$this->tee => 11]])->assertSessionHasErrors(['transfer' => 'The quantity received must be from 0 to 10.']);
        $receive(['received' => [$this->tee => 8]])->assertSessionHas('status', 'The transfer was received.');
        $receive()->assertSessionHasErrors('transfer');   // already received

        expect(($this->level)($this->tee, 'shop')->on_hand)->toBe(8)->and(($this->level)($this->mug, 'shop')->on_hand)->toBe(5)
            ->and(DB::table('stock_transfer_items')->where('variant_id', $this->tee)->value('lost_qty'))->toBe(2);
        ($this->as)($this->viewer)->get("/admin/inventory/transfers/{$t->public_id}")->assertOk()->assertSee('Received');
    });

    it('cancels a transfer on its way, with a reason, and puts the stock back', function () {
        ($this->send)();
        $t = DB::table('stock_transfers')->first();

        ($this->as)($this->mover)->post("/admin/inventory/transfers/{$t->public_id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)($this->mover)->post("/admin/inventory/transfers/{$t->public_id}/cancel", ['reason' => 'Wrong shop'])->assertSessionHas('status', 'The transfer was cancelled and the stock went back.');

        expect(($this->level)($this->tee)->on_hand)->toBe(50)->and(DB::table('stock_transfers')->value('status'))->toBe('cancelled');
        ($this->as)($this->mover)->get('/admin/inventory/transfers/nope')->assertNotFound();
    });
});

describe('stock-takes', function () {
    it('goes from open, to counted, to handed in, to approved by a different person, who corrects the stock', function () {
        $count = ($this->openCount)();
        $page = "/admin/inventory/counts/{$count->public_id}";

        expect(DB::table('stock_count_lines')->where('count_id', $count->id)->count())->toBe(2);
        ($this->as)($this->counter)->get($page)->assertOk()->assertSee('Save counts')->assertSee('TEE-1');
        ($this->as)($this->counter)->post("{$page}/submit")->assertSessionHasErrors(['count' => '2 products have not been counted yet.']);
        ($this->as)($this->counter)->post("{$page}/record", ['counted' => [$this->tee => 'x']])->assertSessionHasErrors('counted.'.$this->tee);
        ($this->as)($this->counter)->post("{$page}/record", ['counted' => [$this->tee => 48, $this->mug => 50]])->assertSessionHas('status', 'The counts were saved.');
        ($this->as)($this->counter)->post("{$page}/submit")->assertSessionHas('status', 'The stock-take was handed in for approval.');

        expect(($this->level)($this->tee)->on_hand)->toBe(50);   // nothing changes until someone else approves
        ($this->as)($this->approver)->get($page)->assertOk()->assertSee('Approve and correct stock')->assertSee('-2');
        ($this->as)($this->approver)->post("{$page}/approve")->assertSessionHas('status', 'The stock-take was approved and stock was corrected.');
        expect(($this->level)($this->tee)->on_hand)->toBe(48)->and(($this->level)($this->mug)->on_hand)->toBe(50)->and(DB::table('stock_counts')->where('id', $count->id)->value('status'))->toBe('approved');
        ($this->as)($this->viewer)->get('/admin/inventory/counts')->assertOk()->assertSee('Approved');
    });

    it('never lets the person who handed it in decide it, and says who must', function () {
        $both = catalogStaff(['inventory.view', 'inventory.count', 'inventory.adjust']);
        ($this->as)($both)->post('/admin/inventory/counts', ['location' => 'main']);
        $count = DB::table('stock_counts')->first();
        $page = "/admin/inventory/counts/{$count->public_id}";
        ($this->as)($both)->post("{$page}/record", ['counted' => [$this->tee => 50, $this->mug => 49]]);
        ($this->as)($both)->post("{$page}/submit");

        ($this->as)($both)->get($page)->assertOk()->assertSee('Waiting for a different person')->assertDontSee('Approve and correct stock');
        ($this->as)($both)->post("{$page}/approve")->assertSessionHasErrors(['count' => 'A different person must decide on this stock-take.']);
        expect(($this->level)($this->mug)->on_hand)->toBe(50);
    });

    it('rejects with a reason and leaves stock alone, and opens only what the SKUs ask for, one at a time per place', function () {
        $count = ($this->openCount)('TEE-1');
        expect(DB::table('stock_count_lines')->where('count_id', $count->id)->pluck('variant_id')->all())->toBe([$this->tee]);
        ($this->as)($this->counter)->post('/admin/inventory/counts', ['location' => 'main'])->assertSessionHasErrors(['count' => 'This place already has a stock-take in progress. Finish or cancel it first.']);
        ($this->as)($this->counter)->post('/admin/inventory/counts', ['location' => 'shop', 'skus' => 'NOPE-9'])->assertSessionHasErrors(['count' => 'That product was not found. (NOPE-9)']);

        $page = "/admin/inventory/counts/{$count->public_id}";
        ($this->as)($this->counter)->post("{$page}/record", ['counted' => [$this->tee => 1]]);
        ($this->as)($this->counter)->post("{$page}/submit");
        ($this->as)($this->approver)->post("{$page}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)($this->approver)->post("{$page}/reject", ['reason' => 'Recount please'])->assertSessionHas('status', 'The stock-take was rejected. Stock did not change.');
        expect(($this->level)($this->tee)->on_hand)->toBe(50);

        $second = ($this->openCount)();   // the place is free again
        ($this->as)($this->counter)->post("/admin/inventory/counts/{$second->public_id}/cancel")->assertSessionHas('status', 'The stock-take was cancelled.');
    });
});

describe('the reports', function () {
    it('lists products at or below their low level with where they are, and ignores a place that does not exist', function () {
        DB::table('stock_levels')->where('variant_id', $this->tee)->update(['low_stock_threshold' => 60]);
        DB::table('stock_levels')->where('variant_id', $this->mug)->update(['low_stock_threshold' => 10]);

        ($this->as)($this->viewer)->get('/admin/inventory/reports/low-stock')->assertOk()->assertSee('TEE-1')->assertSee('Cotton Tee')->assertDontSee('MUG-1');
        ($this->as)($this->viewer)->get('/admin/inventory/reports/low-stock?place=nowhere')->assertOk()->assertSee('TEE-1');
        ($this->as)($this->viewer)->get('/admin/inventory/reports/low-stock?place=shop')->assertOk()->assertSee('Nothing is running low.');
    });

    it('shows how long stock has been sitting', function () {
        ($this->as)($this->viewer)->get('/admin/inventory/reports/aging')->assertOk()->assertSee('0-30')->assertSee('91+')->assertSee('TEE-1')->assertSee('Cotton Tee');
    });
});

describe('both languages', function () {
    it('shows every new screen in Bangla with no missing text', function () {
        $this->mover->forceFill(['locale' => 'bn'])->save();
        ($this->send)();
        $count = ($this->openCount)();
        $public = DB::table('stock_transfers')->value('public_id');
        foreach (['/admin/inventory/transfers', '/admin/inventory/transfers/create', "/admin/inventory/transfers/{$public}", '/admin/inventory/counts', "/admin/inventory/counts/{$count->public_id}", '/admin/inventory/reports/low-stock', '/admin/inventory/reports/aging'] as $page) {
            $html = ($this->as)($this->mover)->get($page)->assertOk()->getContent();
            expect($html)->not->toContain('inventory::')->not->toContain('screens.');
        }
        ($this->as)($this->mover)->get('/admin/inventory/transfers')->assertSee('মজুত স্থানান্তর')->assertSee('পথে আছে');
    });
});
