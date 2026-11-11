<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Reporting\Actions\BuildReportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/* The orders file and the customers file: who may ask, what they get (personal data masked, money only for finance), which orders fall in the period, and that a big period is streamed whole. */

beforeEach(function () {
    shopWorld($this);
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Storage::fake('private');
    Queue::fake();
    config(['reporting.exports.disk' => 'private']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    $this->base = ['reports.view', 'reports.export'];
    $this->fields = ['from' => '2026-03-10', 'to' => '2026-03-11'];
    $this->ask = fn (User $u, string $code, array $fields = []) => ($this->as)($u)->post("/admin/reports/{$code}/export", $fields + $this->fields);
    $this->build = function (User $u): array {
        $export = DB::table('report_exports')->where('requested_by', $u->id)->orderByDesc('id')->first();
        app(BuildReportExport::class)->handle((int) $export->id);
        $export = DB::table('report_exports')->where('id', $export->id)->first();
        $text = str_replace("\xEF\xBB\xBF", '', (string) Storage::disk('private')->get((string) $export->file_key));
        $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', $text)))));

        return ['export' => $export, 'head' => array_shift($rows), 'rows' => $rows];
    };
    ($this->order)(['order_number' => 'VM-A-1', 'contact_name' => 'Amina Rahman', 'contact_phone' => '+8801712345678', 'grand_total_minor' => 96000, 'subtotal_minor' => 96000]);
    ($this->order)(['order_number' => 'VM-A-2', 'contact_name' => 'Amina Rahman', 'contact_phone' => '+8801712345678', 'channel' => 'admin', 'payment_method_code' => 'cod', 'locale' => 'bn', 'grand_total_minor' => 58000, 'subtotal_minor' => 58000, 'placed_at' => '2026-03-11 08:00:00']);
    ($this->order)(['order_number' => 'VM-B-1', 'contact_name' => 'Babul Hasan', 'contact_phone' => '+8801812345678', 'status' => 'cancelled', 'cancelled_at' => '2026-03-10 09:00:00', 'grand_total_minor' => 30000, 'subtotal_minor' => 30000]);
    ($this->order)(['order_number' => 'VM-OLD', 'placed_at' => '2026-03-01 06:00:00']);
});

describe('the orders file', function () {
    it('is asked for only by someone who may export orders, and is not in the list of anyone else', function () {
        $yes = catalogStaff([...$this->base, 'orders.export']);
        $no = catalogStaff($this->base);

        ($this->ask)($no, 'orders_file')->assertForbidden();
        ($this->ask)($yes, 'orders_file')->assertRedirect();
        ($this->as)($no)->get('/admin/reports')->assertOk()->assertDontSee('Orders file');
        ($this->as)($yes)->get('/admin/reports')->assertOk()->assertSee('Orders file');
        ($this->as)($no)->get('/admin/reports/orders_file')->assertForbidden();
    });

    it('holds the orders placed in the period, one row each, and nothing from outside it', function () {
        $user = catalogStaff([...$this->base, 'orders.export']);
        ($this->order)(['order_number' => 'VM-EDGE-IN', 'placed_at' => $this->days->bounds('2026-03-10')[0]->utc()->format('Y-m-d H:i:s')]);                        // the first instant of the first day
        ($this->order)(['order_number' => 'VM-EDGE-OUT', 'placed_at' => $this->days->bounds('2026-03-11')[1]->utc()->format('Y-m-d H:i:s')]);                      // the first instant of the next day
        ($this->order)(['order_number' => 'VM-EDGE-BEFORE', 'placed_at' => $this->days->bounds('2026-03-10')[0]->utc()->subSecond()->format('Y-m-d H:i:s')]);

        ($this->ask)($user, 'orders_file');
        $file = ($this->build)($user);

        expect(array_column($file['rows'], 0))->toEqualCanonicalizing(['VM-A-1', 'VM-A-2', 'VM-B-1', 'VM-EDGE-IN']);
    });

    it('can be narrowed by channel, payment method and language', function () {
        $user = catalogStaff([...$this->base, 'orders.export']);

        ($this->ask)($user, 'orders_file', ['channel' => 'admin']);
        expect(array_column(($this->build)($user)['rows'], 0))->toBe(['VM-A-2']);
        ($this->ask)($user, 'orders_file', ['method' => 'cod']);
        expect(array_column(($this->build)($user)['rows'], 0))->toBe(['VM-A-2']);
        ($this->ask)($user, 'orders_file', ['locale' => 'bn']);
        expect(array_column(($this->build)($user)['rows'], 0))->toBe(['VM-A-2']);
    });

    it('masks names and phone numbers unless the person may see personal data, and leaves out money unless they may see finance', function () {
        $plain = catalogStaff([...$this->base, 'orders.export']);
        $pii = catalogStaff([...$this->base, 'orders.export', 'customers.view_pii', 'reports.finance.view']);

        ($this->ask)($plain, 'orders_file');
        $masked = ($this->build)($plain);
        ($this->ask)($pii, 'orders_file');
        $full = ($this->build)($pii);

        $row = fn (array $file) => collect($file['rows'])->firstWhere(0, 'VM-A-1');
        expect($masked['head'])->not->toContain('Total')->and($full['head'])->toContain('Total')
            ->and(implode(',', $row($masked)))->not->toContain('Amina')->not->toContain('1712345678')->toContain('A***')->toContain('+880****678')
            ->and(implode(',', $row($full)))->toContain('Amina Rahman')->toContain('+8801712345678')->toContain('960.00');
    });

    it('shows masked personal data on screen too', function () {
        $plain = catalogStaff([...$this->base, 'orders.export']);
        $pii = catalogStaff([...$this->base, 'orders.export', 'customers.view_pii']);

        ($this->as)($plain)->get('/admin/reports/orders_file?from=2026-03-10&to=2026-03-11')->assertOk()->assertSee('VM-A-1')->assertDontSee('Amina')->assertDontSee('+8801712345678');
        ($this->as)($pii)->get('/admin/reports/orders_file?from=2026-03-10&to=2026-03-11')->assertOk()->assertSee('Amina Rahman')->assertSee('+8801712345678');
    });

    it('cannot be downloaded by someone who has since lost the right to export orders', function () {
        $user = catalogStaff([...$this->base, 'orders.export']);
        ($this->ask)($user, 'orders_file');
        $export = ($this->build)($user)['export'];
        $link = URL::temporarySignedRoute('admin.reports.exports.download', now()->addMinutes(30), ['publicId' => $export->public_id]);

        ($this->as)($user)->get($link)->assertOk();
        $user->syncPermissions($this->base);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        ($this->as)($user->fresh())->get($link)->assertForbidden();
    });

    it('streams a period of more than a thousand orders whole', function () {
        $user = catalogStaff([...$this->base, 'orders.export']);
        $rows = [];
        foreach (range(1, 1005) as $i) {
            $rows[] = ['public_id' => strtolower((string) Str::ulid()), 'order_number' => sprintf('VM-BIG-%05d', $i), 'contact_name' => 'Bulk', 'contact_phone' => '+8801912345678', 'locale' => 'en', 'channel' => 'web', 'status' => 'confirmed', 'currency' => 'BDT',
                'subtotal_minor' => 0, 'discount_minor' => 0, 'shipping_minor' => 0, 'cod_fee_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 0, 'tax_inclusive' => false, 'payment_method_code' => 'online', 'placed_at' => '2026-03-10 07:00:00', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        ($this->ask)($user, 'orders_file');
        $file = ($this->build)($user);

        expect($file['rows'])->toHaveCount(1005 + 3)->and($file['export']->row_count)->toBe(1005 + 3)->and(count(array_unique(array_column($file['rows'], 0))))->toBe(1005 + 3);
    });
});

describe('the customers file', function () {
    it('is asked for only by someone who may export customers', function () {
        $yes = catalogStaff([...$this->base, 'customers.export']);
        $orders = catalogStaff([...$this->base, 'orders.export']);

        ($this->ask)($orders, 'customers_file')->assertForbidden();
        ($this->ask)($yes, 'customers_file')->assertRedirect();
        ($this->as)($orders)->get('/admin/reports')->assertOk()->assertDontSee('Customers file');
    });

    it('has one row per person: a guest by phone number, a signed-in customer across phone numbers, with the order count and a total that leaves out cancelled orders', function () {
        $user = catalogStaff([...$this->base, 'customers.export', 'customers.view_pii', 'reports.finance.view']);
        $member = catalogStaff(['catalog.view']);
        ($this->order)(['order_number' => 'VM-M-1', 'user_id' => $member->id, 'contact_name' => 'Member One', 'contact_phone' => '+8801511111111', 'grand_total_minor' => 1000, 'subtotal_minor' => 1000, 'placed_at' => '2026-03-10 05:00:00']);
        ($this->order)(['order_number' => 'VM-M-2', 'user_id' => $member->id, 'contact_name' => 'Member One', 'contact_phone' => '+8801522222222', 'grand_total_minor' => 2000, 'subtotal_minor' => 2000, 'placed_at' => '2026-03-11 05:00:00']);

        ($this->ask)($user, 'customers_file');
        $file = ($this->build)($user);
        $by = fn (string $phoneStart) => collect($file['rows'])->first(fn ($r) => str_starts_with(ltrim($r[1], "'"), $phoneStart));

        expect($file['head'])->toBe(['Name', 'Phone', 'Orders', 'First order', 'Last order', 'Spent (not cancelled, BDT)'])
            ->and($file['rows'])->toHaveCount(3)
            ->and($by('+88017')[2])->toBe('2')->and($by('+88017')[5])->toBe('1540.00')     // Amina: two orders
            ->and($by('+88018')[2])->toBe('1')->and($by('+88018')[5])->toBe('0.00')       // Babul: one, cancelled
            ->and(collect($file['rows'])->first(fn ($r) => $r[0] === 'Member One')[2])->toBe('2');
    });

    it('adds up only taka orders in the spent column', function () {
        $user = catalogStaff([...$this->base, 'customers.export', 'customers.view_pii', 'reports.finance.view']);
        ($this->order)(['order_number' => 'VM-USD-1', 'contact_name' => 'Dollar Buyer', 'contact_phone' => '+8801633333333', 'currency' => 'USD', 'grand_total_minor' => 5000, 'subtotal_minor' => 5000]);

        ($this->ask)($user, 'customers_file');
        $row = collect(($this->build)($user)['rows'])->first(fn ($r) => $r[0] === 'Dollar Buyer');

        expect($row[2])->toBe('1')->and($row[5])->toBe('0.00');
    });

    it('masks the name and phone by default and keeps the order count', function () {
        $user = catalogStaff([...$this->base, 'customers.export']);

        ($this->ask)($user, 'customers_file');
        $file = ($this->build)($user);

        expect($file['head'])->not->toContain('Spent (not cancelled, BDT)')
            ->and(implode(',', array_merge(...$file['rows'])))->not->toContain('Amina')->not->toContain('Babul')->not->toContain('1712345678')
            ->and(collect($file['rows'])->pluck(2)->sort()->values()->all())->toBe(['1', '2']);
    });

    it('renders in Bangla', function () {
        $user = catalogStaff([...$this->base, 'customers.export']);

        ($this->as)($user)->withSession(['locale' => 'bn'])->get('/admin/reports/customers_file?from=2026-03-10&to=2026-03-11&lang=bn')->assertOk();
        expect(trans('reporting::catalogue.customers_file.title', [], 'bn'))->toBe('গ্রাহকের ফাইল')->and(trans('reporting::catalogue.orders_file.columns.phone', [], 'bn'))->toBe('ফোন');
    });
});
