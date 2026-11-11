<?php

declare(strict_types=1);

use App\Modules\Catalog\Search\SkuSearch;
use App\Modules\Engagement\Search\TicketSearch;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Search\OrderSearch;
use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\Contracts\SearchProvider;
use App\Modules\Platform\DTOs\TileFigure;
use App\Modules\Platform\Exceptions\SavedFilterException;
use App\Modules\Platform\Support\DashboardRegistry;
use App\Modules\Platform\Support\SavedFilters;
use App\Modules\Platform\Support\SearchProviders;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Reports\ReportCatalogue;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * Phase 10, slice 5a: the dashboard (role-aware tiles that agree with the lists they link to), the staff search box and personal saved filters.
 * The clock is frozen at 2026-03-20 12:00 UTC (a Dhaka afternoon).
 */

class BrokenTile implements DashboardTile
{
    public function code(): string
    {
        return 'broken';
    }

    public function label(): string
    {
        return 'platform::admin.dashboard';
    }

    public function permissions(): array
    {
        return ['dashboard.view'];
    }

    public function figure(): TileFigure
    {
        throw new RuntimeException('boom');
    }
}

class BrokenSearch implements SearchProvider
{
    public function kind(): string
    {
        return 'platform::admin.dashboard';
    }

    public function permissions(): array
    {
        return ['dashboard.view'];
    }

    public function search(string $term, int $limit, bool $seePii): array
    {
        throw new RuntimeException('boom');
    }
}

beforeEach(function () {
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    RateLimiter::clear('admin-search');

    $this->staff = fn (array $permissions) => catalogStaff(array_merge(['dashboard.view'], $permissions));
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    $this->codes = fn (User $u): array => array_map(fn ($t) => $t->code(), app(DashboardRegistry::class)->visibleFor($u));
    $this->owner = catalogStaff([]);
    $this->ticket = function (array $over = []) {
        $owner = $this->owner;

        return DB::table('support_tickets')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'reference' => strtoupper(substr(md5(uniqid()), 0, 4).'-'.substr(md5(uniqid()), 0, 4)), 'user_id' => $owner->id,
            'subject' => 'Where is my parcel?', 'category' => 'delivery', 'status' => 'open', 'locale' => 'en', 'sla_due_at' => now()->addHours(24), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    };
    $this->tiles = fn (User $u): array => collect(($this->as)($u)->get('/admin')->assertOk()->viewData('tiles'))->keyBy('code')->all();
});

describe('the dashboard', function () {
    it('shows each person only the tiles their permissions allow', function () {
        $support = ($this->staff)(['support.view', 'reviews.moderate']);
        $orders = ($this->staff)(['orders.view']);
        $finance = ($this->staff)(['reports.view', 'reports.finance.view', 'payments.view']);
        $analyst = ($this->staff)(['reports.view']);
        $nobody = ($this->staff)([]);

        expect(($this->codes)($support))->toBe(['reviews_waiting', 'tickets_open', 'tickets_late'])
            ->and(($this->codes)($orders))->toBe(['orders_to_confirm', 'orders_to_ship', 'orders_need_review'])
            ->and(($this->codes)($finance))->toContain('net_sales_today')->toContain('orders_today')->toContain('refund_queue')->not->toContain('tickets_open')
            ->and(($this->codes)($analyst))->toBe(['orders_today'])   // the money tile needs the finance permission too
            ->and(($this->codes)($nobody))->toBe([]);

        ($this->as)($nobody)->get('/admin')->assertOk()->assertSee('Welcome to the admin panel.');
        ($this->as)($analyst)->get('/admin')->assertOk()->assertDontSee('Net sales today')->assertSee('Orders today');
    });

    it('counts exactly what the list it links to shows', function () {
        foreach (range(1, 3) as $i) {
            ($this->order)(['status' => 'pending', 'fulfillment_status' => 'unfulfilled']);
        }
        foreach (range(1, 2) as $i) {
            ($this->order)(['status' => 'confirmed', 'fulfillment_status' => 'unfulfilled']);
        }
        ($this->order)(['status' => 'confirmed', 'fulfillment_status' => 'fulfilled']);
        ($this->order)(['status' => 'confirmed', 'fulfillment_status' => 'unfulfilled', 'needs_review' => true]);
        ($this->order)(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);

        $viewer = ($this->staff)(['orders.view']);
        $tiles = ($this->tiles)($viewer);

        expect(collect($tiles)->map(fn ($t) => $t['figure']->value)->all())->toBe(['orders_to_confirm' => 3, 'orders_to_ship' => 3, 'orders_need_review' => 1]);
        foreach ($tiles as $tile) {
            $f = $tile['figure'];
            $list = ($this->as)($viewer)->get(route($f->route, $f->params))->assertOk()->viewData('orders');
            expect($list->total())->toBe($f->value);   // the number and the list always agree
        }
    });

    it('counts payments, refunds and support the same way', function () {
        $o = ($this->order)();
        foreach (['failed', 'failed', 'needs_review'] as $status) {
            DB::table('payments')->insert(['public_id' => strtolower((string) Str::ulid()), 'order_id' => $o, 'method_code' => 'online', 'provider' => 'sslcommerz', 'amount_minor' => 100, 'currency' => 'BDT', 'captured_minor' => 0, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        $p = ($this->pay)($o, 5000, '2026-03-19 05:00:00');
        foreach (['requested', 'approved', 'succeeded', 'rejected'] as $status) {
            ($this->refund)($p, $o, 100, $status, '2026-03-19 06:00:00');
        }
        ($this->ticket)();
        ($this->ticket)(['status' => 'answered']);
        ($this->ticket)(['status' => 'closed', 'closed_at' => now()]);
        ($this->ticket)(['sla_due_at' => now()->subHour()]);   // open, never answered, past its time
        ($this->ticket)(['sla_due_at' => now()->subHour(), 'first_responded_at' => now()->subMinutes(30)]);   // answered in time
        ($this->ticket)(['sla_due_at' => now()->addHour()]);   // open, never answered, still within its time

        $user = ($this->staff)(['payments.view', 'support.view']);
        $tiles = ($this->tiles)($user);

        expect(collect($tiles)->map(fn ($t) => $t['figure']->value)->all())->toEqual(['payments_need_review' => 1, 'payments_failed' => 2, 'refund_queue' => 2, 'tickets_open' => 5, 'tickets_late' => 1]);
        $payments = ($this->as)($user)->get(route('admin.payments.index', $tiles['payments_failed']['figure']->params))->viewData('payments');
        $queue = ($this->as)($user)->get(route('admin.payments.refunds.index'))->viewData('refunds');
        $late = ($this->as)($user)->get(route('admin.support.index', $tiles['tickets_late']['figure']->params))->viewData('page');
        $open = ($this->as)($user)->get(route('admin.support.index', $tiles['tickets_open']['figure']->params))->viewData('page');
        expect($payments->total())->toBe(2)->and($queue->total())->toBe(2)->and($late->total())->toBe(1)->and($open->total())->toBe(5);
    });

    it('shows today against yesterday and the last seven days, the same figures as the sales report', function () {
        ($this->order)(['placed_at' => '2026-03-20 06:00:00', 'subtotal_minor' => 100000, 'grand_total_minor' => 100000]);
        ($this->order)(['placed_at' => '2026-03-20 07:00:00', 'subtotal_minor' => 50000, 'grand_total_minor' => 50000]);
        ($this->order)(['placed_at' => '2026-03-19 06:00:00', 'subtotal_minor' => 30000, 'grand_total_minor' => 30000]);
        ($this->order)(['placed_at' => '2026-03-14 06:00:00', 'subtotal_minor' => 10000, 'grand_total_minor' => 10000]);   // the oldest day shown
        ($this->order)(['placed_at' => '2026-03-13 06:00:00', 'subtotal_minor' => 99900, 'grand_total_minor' => 99900]);   // one day too old
        foreach (['2026-03-13', '2026-03-14', '2026-03-19', '2026-03-20'] as $day) {
            ($this->refresh)($day);
        }

        $tiles = ($this->tiles)(($this->staff)(['reports.view', 'reports.finance.view']));
        $orders = $tiles['orders_today']['figure'];
        $report = app(ReportCatalogue::class)->find('sales_by_day');
        $row = collect(iterator_to_array($report->rows(new ReportFilter('2026-03-20', '2026-03-20')), false))->first();

        expect($orders->value)->toBe(2)->and($orders->before)->toBe(1)->and($orders->series)->toBe([1, 0, 0, 0, 0, 1, 2])
            ->and($orders->value)->toBe((int) $row['orders'])
            ->and($tiles['net_sales_today']['figure']->kind)->toBe('money')->and($tiles['net_sales_today']['figure']->value)->toBe((int) $row['net_sales'])
            ->and($tiles['net_sales_today']['figure']->before)->toBeInt();
    });

    it('does not let one broken tile take the page down', function () {
        app(DashboardRegistry::class)->add(BrokenTile::class);
        $user = ($this->staff)(['orders.view']);

        $page = ($this->as)($user)->get('/admin')->assertOk()->assertSee('Not available right now');
        expect(collect($page->viewData('tiles'))->firstWhere('code', 'broken')['figure'])->toBeNull()->and(collect($page->viewData('tiles'))->firstWhere('code', 'orders_to_confirm')['figure'])->not->toBeNull();
    });

    it('does not ask more questions of the database as the data grows', function () {
        $user = ($this->staff)(['orders.view', 'payments.view', 'support.view', 'inventory.view', 'reviews.moderate', 'reports.view', 'reports.finance.view']);
        $count = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->as)($user)->get('/admin')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count();   // warm caches (permissions, translations)
        $few = $count();
        foreach (range(1, 15) as $i) {
            ($this->order)(['status' => 'pending']);
            ($this->ticket)();
        }

        expect($count())->toBe($few);
    });

    it('renders in Bangla', function () {
        $user = ($this->staff)(['orders.view']);
        ($this->as)($user)->withSession(['locale' => 'bn'])->get('/admin')->assertOk();
        app()->setLocale('bn');

        expect(__('orders::dashboard.orders_to_confirm'))->toBe('নিশ্চিত করার অপেক্ষায় অর্ডার')->and(__('platform::productivity.search.title'))->toBe('খুঁজুন');
    });
});

describe('the search box', function () {
    beforeEach(function () {
        $this->found = ($this->order)(['order_number' => 'VM-2603-000042', 'contact_phone' => '+8801712345678', 'contact_name' => 'Amina Rahman', 'status' => 'pending']);
        ($this->order)(['order_number' => 'VM-2603-000043', 'contact_phone' => '+8801812345678']);
        $this->search = fn (User $u, string $q) => ($this->as)($u)->get('/admin/search?'.http_build_query(['q' => $q]));
    });

    it('finds an order by the start of its number', function () {
        $user = ($this->staff)(['orders.view']);

        ($this->search)($user, 'vm-2603-0000')->assertOk()->assertSee('VM-2603-000042')->assertSee('VM-2603-000043');
        ($this->search)($user, 'VM-2603-000042')->assertOk()->assertSee('VM-2603-000042')->assertDontSee('VM-2603-000043');
    });

    it('finds an order by its exact phone number, in any way of writing it, without showing the number to people who may not see it', function () {
        $user = ($this->staff)(['orders.view']);
        $pii = ($this->staff)(['orders.view', 'customers.view_pii']);

        foreach (['01712345678', '+8801712345678', '8801712345678', '০১৭১২৩৪৫৬৭৮'] as $term) {
            $hits = ($this->search)($user, $term)->assertOk()->assertSee('VM-2603-000042')->assertDontSee('VM-2603-000043')->viewData('groups')[0]['hits'];   // the box itself shows what was typed
            expect($hits)->toHaveCount(1)->and($hits[0]->detail)->not->toContain('1712345678')->and($hits[0]->detail)->toContain('+880');
        }
        ($this->search)($pii, '01712345678')->assertOk()->assertSee('VM-2603-000042')->assertSee('+8801712345678');
    });

    it('shows only the kinds a person may open', function () {
        $ticket = DB::table('support_tickets')->where('id', ($this->ticket)(['reference' => 'K7M2-Q9XD']))->first();
        $orders = ($this->staff)(['orders.view']);
        $support = ($this->staff)(['support.view']);

        ($this->search)($orders, 'K7M2')->assertOk()->assertDontSee('K7M2-Q9XD')->assertSee('Nothing found.');
        ($this->search)($support, 'K7M2')->assertOk()->assertSee('K7M2-Q9XD')->assertSee(route('admin.support.show', $ticket->public_id));
        expect(($this->search)($support, 'VM-2603-000042')->assertOk()->viewData('groups'))->toBe([]);   // the box itself shows what was typed
    });

    it('finds a product by the start of a SKU', function () {
        shopWorld($this);
        $sku = (string) DB::table('product_variants')->value('sku');
        $user = ($this->staff)(['catalog.view']);

        ($this->search)($user, substr($sku, 0, 4))->assertOk()->assertSee($sku);
        ($this->search)(($this->staff)(['orders.view']), substr($sku, 0, 4))->assertOk()->assertDontSee($sku);
    });

    it('leaves out the SKU of a deleted product', function () {
        shopWorld($this);
        $sku = (string) DB::table('product_variants')->value('sku');
        $user = ($this->staff)(['catalog.view']);

        DB::table('products')->update(['deleted_at' => now()]);
        ($this->search)($user, substr($sku, 0, 4))->assertOk()->assertDontSee($sku);
    });

    it('shows at most five of each kind and each provider honours the limit it is given', function () {
        foreach (range(44, 50) as $i) {
            ($this->order)(['order_number' => 'VM-2603-0000'.$i]);
        }
        $user = ($this->staff)(['orders.view']);

        expect(($this->search)($user, 'VM-2603-0000')->assertOk()->viewData('groups')[0]['hits'])->toHaveCount(5)
            ->and(app(OrderSearch::class)->search('VM-2603-0000', 2, false))->toHaveCount(2);
    });

    it('does not let the wildcard characters or too-short words reach the providers\' queries', function () {
        ($this->ticket)(['reference' => 'K7M2-Q9XD']);
        shopWorld($this);
        $sku = (string) DB::table('product_variants')->value('sku');

        expect(app(OrderSearch::class)->search('VM_2603', 5, false))->toBe([])   // _ is a plain character, not "any one"
            ->and(app(OrderSearch::class)->search('___', 5, false))->toBe([])
            ->and(app(TicketSearch::class)->search('K7', 5, false))->toBe([])
            ->and(app(TicketSearch::class)->search('K7M2', 5, false))->toHaveCount(1)
            ->and(app(SkuSearch::class)->search(substr($sku, 0, 2), 5, false))->toBe([])
            ->and(app(SkuSearch::class)->search(substr($sku, 0, 4), 5, false))->not->toBe([]);
    });

    it('asks for at least three characters and treats the wildcard characters as plain text', function () {
        $user = ($this->staff)(['orders.view', 'support.view', 'catalog.view']);

        ($this->search)($user, 'VM')->assertOk()->assertSee('Type at least 3 characters.')->assertDontSee('VM-2603-000042');
        foreach (['%%%', '___', '%_%', '\\\\\\'] as $term) {
            ($this->search)($user, $term)->assertOk()->assertDontSee('VM-2603-000042')->assertDontSee('VM-2603-000043');
        }
        ($this->search)($user, str_repeat('x', 61))->assertSessionHasErrors('q');
    });

    it('never writes what was typed to the log', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $user = ($this->staff)(['orders.view']);
        ($this->search)($user, '01712345678')->assertOk();
        app(SearchProviders::class)->add(BrokenSearch::class);
        ($this->search)($user, '01712345678')->assertOk()->assertSee('VM-2603-000042');   // one broken kind does not take the others down

        expect(implode(' ', $logged))->not->toContain('01712345678')->not->toContain('8801712345678')->toContain('admin.search_failed');
    });

    it('is closed to guests and limited in how often it can be used', function () {
        $this->get('/admin/search?q=abcdef')->assertRedirect();

        $user = ($this->staff)(['orders.view']);
        for ($i = 0; $i < 30; $i++) {
            ($this->search)($user, 'VM-2603')->assertOk();
        }
        ($this->search)($user, 'VM-2603')->assertStatus(429);
    });

    it('puts the box in the header of every staff page and renders in Bangla', function () {
        ($this->as)(($this->staff)(['orders.view']))->get('/admin')->assertOk()->assertSee('name="q"', false)->assertSee(route('admin.search'));
        app()->setLocale('bn');
        expect(__('platform::productivity.search.none'))->toBe('কিছু পাওয়া যায়নি।');
    });
});

describe('saved filters', function () {
    beforeEach(function () {
        $this->me = ($this->staff)(['orders.view', 'support.view']);
        $this->other = ($this->staff)(['orders.view']);
        $this->save = fn (User $u, array $body) => ($this->as)($u)->post('/admin/saved-filters', $body);
        $this->mine = fn (User $u, string $screen = 'orders'): array => app(SavedFilters::class)->for($u->id, $screen);
    });

    it('is saved, listed on its own list and applies the same filters again', function () {
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'To confirm', 'f' => ['status' => 'pending', 'payment' => '']])->assertRedirect(route('admin.orders.index', ['status' => 'pending']))->assertSessionHas('status');

        $saved = ($this->mine)($this->me);
        expect($saved)->toHaveCount(1)->and($saved[0]['name'])->toBe('To confirm')->and($saved[0]['query'])->toBe('status=pending')->and($saved[0]['url'])->toBe(route('admin.orders.index').'?status=pending');
        ($this->as)($this->me)->get('/admin/orders')->assertOk()->assertSee('To confirm')->assertSee($saved[0]['url']);
        ($this->as)($this->me)->get('/admin/orders?status=pending')->assertOk()->assertSee('name="screen" value="orders"', false)->assertSee('name="f[status]" value="pending"', false);
        ($this->as)($this->me)->get('/admin/support')->assertOk()->assertDontSee('To confirm');
    });

    it('stores only the filters the list allows, and only valid ones', function () {
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'Mixed', 'f' => ['status' => 'confirmed', 'fulfillment' => 'unfulfilled', 'evil' => '<script>', '_token' => 'x', 'page' => '9']])->assertSessionHas('status');
        expect(DB::table('saved_filters')->value('query'))->toBe('fulfillment=unfulfilled&status=confirmed');

        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'Bad value', 'f' => ['status' => 'not-a-status']])->assertSessionHasErrors('saved_filter');
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'Nothing', 'f' => []])->assertSessionHasErrors('saved_filter');
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'Only junk', 'f' => ['evil' => 'x']])->assertSessionHasErrors('saved_filter');
        ($this->save)($this->me, ['screen' => 'orders', 'name' => str_repeat('n', 61), 'f' => ['status' => 'pending']])->assertSessionHasErrors('name');
        ($this->save)($this->me, ['screen' => 'orders', 'name' => '', 'f' => ['status' => 'pending']])->assertSessionHasErrors('name');
        expect(DB::table('saved_filters')->count())->toBe(1);
    });

    it('does not keep the same name twice, and keeps at most twenty a list', function () {
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'One', 'f' => ['status' => 'pending']])->assertSessionHas('status');
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'One', 'f' => ['status' => 'confirmed']])->assertSessionHasErrors('saved_filter');
        ($this->save)($this->other, ['screen' => 'orders', 'name' => 'One', 'f' => ['status' => 'confirmed']])->assertSessionHas('status');   // another person's own list

        foreach (range(2, 20) as $i) {
            app(SavedFilters::class)->save($this->me->id, 'orders', "Filter {$i}", ['q' => "x{$i}"]);   // straight through the service: the route is limited to 20 a minute
        }
        expect(($this->mine)($this->me))->toHaveCount(20);
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'One too many', 'f' => ['q' => 'zz']])->assertSessionHasErrors('saved_filter');
        ($this->save)($this->me, ['screen' => 'support', 'name' => 'Other list', 'f' => ['overdue' => '1']])->assertSessionHas('status');   // a different list has its own twenty
        expect(($this->mine)($this->me))->toHaveCount(20)->and(($this->mine)($this->me, 'support'))->toHaveCount(1);
    });

    it('refuses a name that is empty or over sixty characters even when called directly', function () {
        $save = fn (string $name) => fn () => app(SavedFilters::class)->save($this->me->id, 'orders', $name, ['q' => 'zz']);

        expect($save(''))->toThrow(SavedFilterException::class)->and($save(str_repeat('n', 61)))->toThrow(SavedFilterException::class);
        app(SavedFilters::class)->save($this->me->id, 'orders', str_repeat('n', 60), ['q' => 'zz']);
        expect(($this->mine)($this->me))->toHaveCount(1);
    });

    it('refuses a list that does not exist even when called directly, and trims what it keeps', function () {
        expect(fn () => app(SavedFilters::class)->save($this->me->id, 'invoices', 'Nope', ['q' => 'zz']))->toThrow(SavedFilterException::class, 'unknown_screen');

        app(SavedFilters::class)->save($this->me->id, 'orders', 'Trim', ['q' => '  zz  ']);
        expect(($this->mine)($this->me)[0]['query'] ?? null)->toBe('q=zz');
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'Odd flag', 'f' => ['review' => '2']])->assertSessionHasErrors('saved_filter');
    });

    it('belongs to its owner: nobody else sees it or can remove it', function () {
        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'Mine', 'f' => ['status' => 'pending']]);
        $id = ($this->mine)($this->me)[0]['id'];

        expect(($this->mine)($this->other))->toBe([]);
        ($this->as)($this->other)->get('/admin/orders')->assertOk()->assertDontSee('Mine');
        ($this->as)($this->other)->delete("/admin/saved-filters/{$id}")->assertNotFound();
        expect(($this->mine)($this->me))->toHaveCount(1);

        ($this->as)($this->me)->delete("/admin/saved-filters/{$id}")->assertRedirect()->assertSessionHas('status');
        expect(($this->mine)($this->me))->toBe([]);
        ($this->as)($this->me)->delete("/admin/saved-filters/{$id}")->assertNotFound();   // already gone
    });

    it('is only for a list the person may open, and only for lists that exist', function () {
        $noOrders = ($this->staff)(['support.view']);

        ($this->save)($noOrders, ['screen' => 'orders', 'name' => 'Sneaky', 'f' => ['status' => 'pending']])->assertForbidden();
        ($this->save)($this->me, ['screen' => 'invoices', 'name' => 'Nope', 'f' => ['status' => 'pending']])->assertNotFound();
        $this->post('/admin/saved-filters', ['screen' => 'orders', 'name' => 'x'])->assertRedirect();
        expect(DB::table('saved_filters')->count())->toBe(0);
        ($this->as)($noOrders)->get('/admin/support')->assertOk()->assertSee('Saved filters');
    });

    it('is limited in how often it can be used', function () {
        RateLimiter::clear('saved-filters');
        for ($i = 0; $i < 20; $i++) {
            ($this->save)($this->me, ['screen' => 'orders', 'name' => 'n'.$i, 'f' => ['q' => 'a'.$i]]);
        }

        ($this->save)($this->me, ['screen' => 'orders', 'name' => 'late', 'f' => ['q' => 'zzz']])->assertStatus(429);
    });

    it('is held by the database too', function () {
        $now = now();
        $row = ['public_id' => strtolower((string) Str::ulid()), 'user_id' => $this->me->id, 'screen' => 'orders', 'name' => 'A', 'query' => 'status=pending', 'created_at' => $now, 'updated_at' => $now];

        DB::table('saved_filters')->insert($row);
        expect(fn () => DB::table('saved_filters')->insert(['public_id' => strtolower((string) Str::ulid())] + $row))->toThrow(QueryException::class)   // the same name twice
            ->and(fn () => DB::table('saved_filters')->insert(['public_id' => strtolower((string) Str::ulid()), 'name' => ''] + $row))->toThrow(QueryException::class, 'saved_filters_text_check')
            ->and(fn () => DB::table('saved_filters')->insert(['public_id' => strtolower((string) Str::ulid()), 'name' => 'B', 'query' => ''] + $row))->toThrow(QueryException::class, 'saved_filters_text_check');
    });

    it('renders in Bangla', function () {
        app()->setLocale('bn');

        expect(__('platform::productivity.saved.title'))->toBe('সংরক্ষিত ফিল্টার')->and(__('platform::productivity.saved.errors.too_many'))->toContain('২০');
    });
});
